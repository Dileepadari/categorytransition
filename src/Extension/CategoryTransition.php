<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.category_transition
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Workflow\CategoryTransition\Extension;

use Joomla\CMS\Event\Model;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Workflow\WorkflowPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Workflow Category Transition Plugin
 *
 * @since  DEPLOY_VERSION
 */
final class CategoryTransition extends CMSPlugin implements SubscriberInterface
{
    use WorkflowPluginTrait;
    use DatabaseAwareTrait;
    /**
     * Load the language file on instantiation.
     *
     * @var    bool
     * @since  DEPLOY_VERSION
     */
    protected $autoloadLanguage = true;


    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm'       => 'onContentPrepareForm',
            'onWorkflowAfterTransition'  => 'onWorkflowAfterTransition',
        ];
    }

    /**
     * The form event.
     *
     * @param   Model\PrepareFormEvent  $event  The event
     *
     * @since   DEPLOY_VERSION
     */
    public function onContentPrepareForm(Model\PrepareFormEvent $event)
    {
        $form = $event->getForm();
        $data = $event->getData();
        $context = $form->getName();

        // Extend the transition form
        if ($context === 'com_workflow.transition') {
            $this->extendTransitionForm($form, $data);
            return;
        }

        if ($context === 'com_content.article') {
            if ($data && $data->id === null) {
                return;
            }
            $this->disableCategoryField($form, $data);
            return;
        }
    }

    /**
     * add certain fields in the item form view, when we want to take over this function in the transition
     * Check also for the workflow implementation and if the field exists
     *
     * @param   Form      $form  The form
     * @param   object    $data  The data
     *
     * @return  boolean
     *
     * @since   DEPLOY_VERSION
     */
    protected function extendTransitionForm(Form $form, $data)
    {
        // Get the plugin path
        $path = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/forms/action.xml';

        // Check if the form XML file exists and load it
        if (is_file($path)) {
            $form->loadFile($path);
        }
    }

    /**
     * Disable the category field in the article form.
     *
     * @param   Form      $form  The form
     * @param   object    $data  The data
     *
     * @return  void
     *
     * @since   DEPLOY_VERSION
     */
    protected function disableCategoryField(Form $form)
    {
        // Get the current category ID value
        $catid = $form->getValue('catid');

        $form->setFieldAttribute('catid', 'readonly', 'true');
        $form->setFieldAttribute('catid', 'value', $catid);
    }


    /**
     * Method to handle the workflow transition event.
     *
     * @param   WorkflowTransitionEvent  $event  The event object
     *
     * @return  void
     *
     * @since   DEPLOY_VERSION
     */
    public static function onWorkflowAfterTransition(WorkflowTransitionEvent $event): void
    {
        $app = Factory::getApplication();
        $pks = $event->getArgument('pks');
        $transition = $event->getArgument('transition');

        if (!self::validateTransition($app, $transition)) {
            return;
        }

        $options = $transition->options ?? null;
        $categoryId = (int) $options->get('category_id');

        if (!self::validatePrimaryKeys($app, $pks)) {
            return;
        }

        $errors = 0;

        foreach ($pks as $pk) {
            if (!self::processArticle($app, $pk, $categoryId)) {
                $errors++;
            }
        }

        if ($errors > 0) {
            $app->enqueueMessage(sprintf('Encountered errors with %d articles', $errors), 'warning');
        }
    }

    /**
     * Validate the transition object.
     *
     * @param   \Joomla\CMS\Factory  $app        The application object
     * @param   object               $transition The transition object
     *
     * @return  bool
     *
     * @since   DEPLOY_VERSION
     */
    private static function validateTransition($app, $transition): bool
    {
        if (!is_object($transition)) {
            $app->enqueueMessage('Invalid transition object type', 'error');
            return false;
        }

        if (!($transition->options instanceof \Joomla\Registry\Registry)) {
            $app->enqueueMessage('Transition options are not a valid Registry object', 'error');
            return false;
        }

        return true;
    }

    /**
     * Validate the primary keys.
     *
     * @param   \Joomla\CMS\Factory  $app  The application object
     * @param   array                $pks  The primary keys
     *
     * @return  bool
     *
     * @since   DEPLOY_VERSION
     */
    private static function validatePrimaryKeys($app, $pks): bool
    {
        if (empty($pks) || !is_array($pks)) {
            $app->enqueueMessage('No valid primary keys found', 'error');
            return false;
        }

        return true;
    }

    /**
     * Process the article and update its category.
     *
     * @param   \Joomla\CMS\Factory  $app        The application object
     * @param   int                  $pk         The primary key
     * @param   int                  $categoryId The category ID
     *
     * @return  bool
     *
     * @since   DEPLOY_VERSION
     */
    private static function processArticle($app, $pk, $categoryId): bool
    {
        $result = false;

        try {
            $articleTable = Table::getInstance('Content');
            if (!$articleTable->load($pk)) {
                $app->enqueueMessage('Article not found: ' . $pk, 'warning');
            } elseif ($articleTable->catid == $categoryId) {
                $result = true;
            } else {
                $originalData = clone $articleTable;
                if ($categoryId && $categoryId > 0) {
                    $articleTable->catid = $categoryId;
                }

                $articleTable->modified = $originalData->modified;
                $articleTable->modified_by = $originalData->modified_by;

                if (!$articleTable->store()) {
                    $app->enqueueMessage('Failed to update article ID ' . $pk . ': ' . $articleTable->getError(), 'error');
                } else {
                    $result = true;
                }
            }
        } catch (\RuntimeException $e) {
            $app->enqueueMessage('Error processing article ' . $pk . ': ' . $e->getMessage(), 'error');
        }

        return $result;
    }
}
