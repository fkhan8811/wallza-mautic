<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class MauticWallzaIdentityExtension extends Extension
{
    private const DEFAULT_PARAMETERS = [
        'wallza_sso_authorize_url' => '',
        'wallza_sso_token_url'     => '',
        'wallza_sso_tenant_id'     => '',
        'wallza_sso_hmac_secret'   => '',
        'wallza_sso_base_url'      => '',
        'wallza_sso_issuer'        => 'https://api.wallza.in',
    ];

    /**
     * @param mixed[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        foreach (self::DEFAULT_PARAMETERS as $name => $value) {
            if (!$container->hasParameter($name)) {
                $container->setParameter($name, $value);
            }
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config'));
        $loader->load('services.php');
    }
}
