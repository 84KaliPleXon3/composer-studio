<?php

namespace Studio\Composer;

use Composer\Composer;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Repository\PathRepository;
use Composer\Repository\WritableRepositoryInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Package;
use Composer\Package\RootPackageInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Studio\Config\Config;

/**
 * Class StudioPlugin
 *
 * @package Studio\Composer
 */
class StudioPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var DownloadManager
     */
    protected $downloadManager;

    /**
     * @var InstallationManager
     */
    protected $installationManager;

    /**
     * @var RootPackageInterface
     */
    protected $rootPackage;

    /**
     * StudioPlugin constructor.
     *
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->installationManager = $composer->getInstallationManager();
        $this->downloadManager = $composer->getDownloadManager();
        $this->rootPackage = $composer->getPackage();
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        // TODO: Before update, append Studio path repositories
        return [
            ScriptEvents::POST_UPDATE_CMD => 'symlinkStudioPackages',
            ScriptEvents::PRE_UPDATE_CMD => 'unlinkStudioPackages',
            ScriptEvents::POST_UPDATE_CMD => 'symlinkStudioPackages',
            ScriptEvents::POST_INSTALL_CMD => 'symlinkStudioPackages',
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'symlinkStudioPackages'
            // ScriptEvents::PRE_AUTOLOAD_DUMP => 'loadStudioPackagesForDump',
        ];
    }

    public function symlinkStudioPackages_old()
    {
        $intersection = $this->getManagedPackages();

        // Create symlinks for all left-over packages in vendor/composer/studio
        $destination = $this->composer->getConfig()->get('vendor-dir') . '/composer/studio';
        (new Filesystem())->emptyDirectory($destination);
        $studioRepo = new InstalledFilesystemRepository(
            new JsonFile($destination . '/installed.json')
        );

        $installationManager = $this->composer->getInstallationManager();

        // Get local repository which contains all installed packages
        $installed = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($intersection as $package) {
            $original = $installed->findPackage($package->getName(), '*');

            $installationManager->getInstaller($original->getType())
                ->uninstall($installed, $original);

            $installationManager->getInstaller($package->getType())
                ->install($studioRepo, $package);
        }

        $studioRepo->write();

        // TODO: Run dump-autoload again
    }

    /**
     * Symlink all Studio-managed packages
     *
     * After `composer update`, we replace all packages that can also be found
     * in paths managed by Studio with symlinks to those paths.
     */

    public function loadStudioPackagesForDump()
    {
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $intersection = $this->getManagedPackages();

        $packagesToReplace = [];
        foreach ($intersection as $package) {
            $packagesToReplace[] = $package->getName();
        }

        // Remove all packages with same names as one of symlinked packages
        $packagesToRemove = [];
        foreach ($localRepo->getCanonicalPackages() as $package) {
            if (in_array($package->getName(), $packagesToReplace)) {
                $packagesToRemove[] = $package;
            }
        }
        foreach ($packagesToRemove as $package) {
            $localRepo->removePackage($package);
        }

        // Add symlinked packages to local repository
        foreach ($intersection as $package) {
            $localRepo->addPackage(clone $package);
        }
    }

    /**
     * @param WritableRepositoryInterface $installedRepo
     * @param PathRepository[] $managedRepos
     * @return PackageInterface[]
     */
    private function getIntersection(WritableRepositoryInterface $installedRepo, $managedRepos)
    {
        $managedRepo = new CompositeRepository($managedRepos);

        return array_filter(
            array_map(
                function (PackageInterface $package) use ($managedRepo) {
                    return $managedRepo->findPackage($package->getName(), '*');
                },
                $installedRepo->getCanonicalPackages()
            )
        );
    }

    private function getManagedPackages()
    {
        $composerConfig = $this->composer->getConfig();

        // Get array of PathRepository instances for Studio-managed paths
        $managed = [];
        foreach ($this->getManagedPaths() as $path) {
            $managed[] = new PathRepository(
                ['url' => $path],
                $this->io,
                $composerConfig
            );
        }

        // Intersect PathRepository packages with local repository
        $intersection = $this->getIntersection(
            $this->composer->getRepositoryManager()->getLocalRepository(),
            $managed
        );

        foreach ($intersection as $package) {
            $this->write('Loading package ' . $package->getUniqueName());
        }

        return $intersection;
    }

    /*
     * Symlink all managed paths by studio
     *
     * This happens just before the autoload generator kicks in except with --no-autoloader
     * In that case we create the symlinks on the POST_UPDATE, POST_INSTALL events
     *
     */
    public function symlinkStudioPackages()
    {
        $studioDir = realpath($this->rootPackage->getTargetDir()) . DIRECTORY_SEPARATOR . '.studio';
        foreach ($this->getManagedPaths() as $path) {
            $package = $this->createPackageForPath($path);
            $destination = $this->installationManager->getInstallPath($package);

            // Creates the symlink to the package
            if (!$this->filesystem->isSymlinkedDirectory($destination) &&
                !$this->filesystem->isJunction($destination)
            ) {
                $this->io->write("[Studio] Creating link to $path for package " . $package->getName());

                // Create copy of original in the `.studio` directory,
                // we use the original on the next `composer update`
                if (is_dir($destination)) {
                    $copyPath = $studioDir . DIRECTORY_SEPARATOR . $package->getName();
                    $this->filesystem->ensureDirectoryExists($copyPath);
                    $this->filesystem->copyThenRemove($destination, $copyPath);
                }

                // Download the managed package from its path with the composer downloader
                $pathDownloader = $this->downloadManager->getDownloader('path');
                $pathDownloader->download($package, $destination);
            }
        }

        // ensure the `.studio` directory only if we manage paths.
        // without this check studio will create the `.studio` directory
        // in all projects where composer is used
        if (count($this->getManagedPaths())) {
            $this->filesystem->ensureDirectoryExists('.studio');
        }

        // if we have managed paths or did have we copy the current studio.json
        if (count($this->getManagedPaths()) > 0 ||
            count($this->getPreviouslyManagedPaths()) > 0
        ) {
            // If we have the current studio.json copy it to the .studio directory
            $studioFile = realpath($this->rootPackage->getTargetDir()) . DIRECTORY_SEPARATOR . 'studio.json';
            if (file_exists($studioFile)) {
                copy($studioFile, $studioDir . DIRECTORY_SEPARATOR . 'studio.json');
            }
        }
    }

    /**
     * Removes all symlinks managed by studio
     *
     */
    public function unlinkStudioPackages()
    {
        $studioDir = realpath($this->rootPackage->getTargetDir()) . DIRECTORY_SEPARATOR  . '.studio';
        $paths = array_merge($this->getPreviouslyManagedPaths(), $this->getManagedPaths());

        foreach ($paths as $path) {
            $package = $this->createPackageForPath($path);
            $destination = $this->installationManager->getInstallPath($package);

            if ($this->filesystem->isSymlinkedDirectory($destination) ||
                $this->filesystem->isJunction($destination)
            ) {
                $this->io->write("[Studio] Removing linked path $path for package " . $package->getName());
                $this->filesystem->removeDirectory($destination);

                // If we have an original copy move it back
                $copyPath = $studioDir . DIRECTORY_SEPARATOR . $package->getName();
                if (is_dir($copyPath)) {
                    $this->filesystem->copyThenRemove($copyPath, $destination);
                }
            }
        }
    }

    /**
     * Creates package from given path
     *
     * @param string $path
     * @return Package
     */
    private function createPackageForPath($path)
    {
        $json = (new JsonFile(
            realpath($path . DIRECTORY_SEPARATOR . 'composer.json')
        ))->read();
        $json['version'] = 'dev-master';

        // branch alias won't work, otherwise the ArrayLoader::load won't return an instance of CompletePackage
        unset($json['extra']['branch-alias']);

        $loader = new ArrayLoader();
        $package = $loader->load($json);
        $package->setDistUrl($path);

        return $package;
    }

    /**
     * Get the list of paths that are being managed by Studio.
     *
     * @return array
     */
    private function getManagedPaths()
    {
        $targetDir = realpath($this->rootPackage->getTargetDir());
        $config = Config::make($targetDir . DIRECTORY_SEPARATOR  . 'studio.json');

        return $config->getPaths();
    }

    /**
     * Get last known managed paths by studio
     *
     * @return array
     */
    private function getPreviouslyManagedPaths()
    {
        $targetDir = realpath($this->rootPackage->getTargetDir()) . DIRECTORY_SEPARATOR . '.studio';
        $config = Config::make($targetDir . DIRECTORY_SEPARATOR  . 'studio.json');

        return $config->getPaths();
    }

    private function write($msg)
    {
        $this->io->writeError("[Studio] $msg");
    }
}
