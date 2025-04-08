<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.category_transition
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined(constant_name: '_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Workflow\CategoryTransition\Extension\CategoryTransition;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   4.4.0
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new CategoryTransition(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('workflow', 'category_transition')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};