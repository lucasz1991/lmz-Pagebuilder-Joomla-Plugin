<?php

/**
 * @package     LMZ.Plugin
 * @subpackage  System.lmzsuitemenu
 *
 * @copyright   (C) 2026 LMZ Media. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use LMZ\Plugin\System\Lmzsuitemenu\Extension\Lmzsuitemenu;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Lmzsuitemenu(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'lmzsuitemenu')
                );
                $plugin->setApplication(Factory::getApplication());
                /* Wird gebraucht, um den alten Menüeintrag der Suite zu
                   finden - siehe alteWurzelId(). */
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
