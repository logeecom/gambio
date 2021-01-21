<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit174aa7fe8638e1a4cd6856760cf36612
{
    public static $prefixLengthsPsr4 = array (
        'M' => 
        array (
            'Mollie\\Gambio\\' => 14,
            'Mollie\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Mollie\\Gambio\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Components',
        ),
        'Mollie\\' => 
        array (
            0 => __DIR__ . '/..' . '/mollie/integration-core/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit174aa7fe8638e1a4cd6856760cf36612::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit174aa7fe8638e1a4cd6856760cf36612::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}