<?php declare(strict_types=1);

namespace Shopware\Core\Framework;

use Shopware\Core\Framework\DependencyInjection\CompilerPass\DefinitionRegistryCompilerPass;
use Shopware\Core\Framework\DependencyInjection\CompilerPass\ExtensionCompilerPass;
use Shopware\Core\Framework\DependencyInjection\FrameworkExtension;
use Shopware\Core\Framework\Doctrine\BridgeDatabaseCompilerPass;
use Shopware\Core\Framework\ORM\EntityDefinition;
use Shopware\Core\Framework\ORM\ExtensionRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class Framework extends Bundle
{
    public const VERSION = '___VERSION___';
    public const VERSION_TEXT = '___VERSION_TEXT___';
    public const REVISION = '___REVISION___';

    protected $name = 'Shopware';

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): Extension
    {
        return new FrameworkExtension();
    }

    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/DependencyInjection/'));
        $loader->load('services.xml');
        $loader->load('orm.xml');
        $loader->load('filesystem.xml');
        $loader->load('api.xml');
        $loader->load('plugin.xml');

        $container->addCompilerPass(new BridgeDatabaseCompilerPass());
        $container->addCompilerPass(new ExtensionCompilerPass());
        $container->addCompilerPass(new DefinitionRegistryCompilerPass());
    }

    public function boot()
    {
        parent::boot();

        /** @var ExtensionRegistry $registry */
        $registry = $this->container->get(ExtensionRegistry::class);
        foreach ($registry->getExtensions() as $extension) {
            /** @var EntityDefinition $definition */
            $definition = $extension->getDefinitionClass();
            $definition::addExtension($extension);
        }
    }
}
