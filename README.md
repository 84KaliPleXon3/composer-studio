# studio

Develop your Composer libraries with style.

This package makes it easy to develop Composer packages while using them.

Instead of installing the packages you're actively working on as a dependency, use Studio to manage your libraries.
It will take care of autoloading your library's dependencies, and you won't have to develop in the `vendor` directory.

Studio also knows how to configure development tools that might be part of your workflow.
This includes the following:

- PhpUnit
- TravisCI

This list will only get longer in the future.

## Installation

Studio can be installed globally or per project, with Composer:

Globally (recommended): `composer global require franzliedke/studio`
(use as `studio`)

Per project: `composer require --dev franzliedke/studio`
(use as `vendor/bin/studio`)

## Usage

### Create a new package skeleton

    studio create foo

This command creates a skeleton for a new Composer package, already filled with some helpful files to get you started.
In the above example, we're creating a new package in the folder `foo` in your project root.
All its dependencies will be available when using Composer.

During creation, you will be asked a series of questions to configure your skeleton.
This will include things like configuration for testing tools, Travis CI, and autoloading.

### Manage existing packages by cloning a Git repository

    studio create bar --git git@github.com:me/myrepo.git

This will clone the given Git repository to the `bar` directory and install its dependencies.

## Contributing

Feel free to send pull requests or create issues if you come across problems or have great ideas.
Any input is appreciated!
