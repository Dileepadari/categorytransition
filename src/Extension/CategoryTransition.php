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
 * @since  5.2.0
 */
final class CategoryTransition extends CMSPlugin implements SubscriberInterface
{
    use WorkflowPluginTrait;
    use DatabaseAwareTrait;
    /**
     * Load the language file on instantiation.
     *
     * @var    bool
     * @since  4.0.0
     */
    protected $autoloadLanguage = true;


    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareForm'       => 'onContentPrepareForm',
            'onWorkflowAfterTransition'  => 'onWorkflowAfterTransition',
            'onContentBeforeSave'        => 'onContentBeforeSave',
        ];
    }

    /**
     * The form event.
     *
     * @param   Model\PrepareFormEvent  $event  The event
     *
     * @since   5.2.0
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

        if( $context === 'com_content.article'){
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
     * @since   4.0.0
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

    protected function disableCategoryField(Form $form, $data)
    {
        // Get the current category ID value
        $catid = $form->getValue('catid');

        $form->setFieldAttribute('catid', 'readonly', 'true');
        $form->setFieldAttribute('catid', 'value', $catid);
    }



    public static function onWorkflowAfterTransition(WorkflowTransitionEvent $event): void
    {
        $app = Factory::getApplication();
        $context = $event->getArgument('extension');
        $pks = $event->getArgument('pks');
        $transition = $event->getArgument('transition');

        if (!is_object($transition)) {
            $app->enqueueMessage('Invalid transition object type', 'error');
            return;
        }
        $options = $transition->options ?? null;

        if (!($options instanceof \Joomla\Registry\Registry)) {
            $app->enqueueMessage('Transition options are not a valid Registry object', 'error');
            return;
        }

        $categoryId = (int) $options->get('category_id');

        if ($categoryId <= 0) {
            $app->enqueueMessage('Invalid category ID specified: ' . $categoryId, 'error');
            return;
        }

        if (empty($pks) || !is_array($pks)) {
            $app->enqueueMessage('No valid primary keys found', 'error');
            return;
        }

        $form = new Form('com_content.article');
        $form->loadFile(JPATH_ADMINISTRATOR . '/components/com_content/forms/article.xml');

        // Process each article
        $processed = 0;
        $errors = 0;
        foreach ($pks as $pk) {
            try {
                // Load article table
                $articleTable = Table::getInstance('Content');
                if (!$articleTable->load($pk)) {
                    $app->enqueueMessage('Article not found: ' . $pk, 'warning');
                    $errorCount++;
                    continue;
                }
                if ($articleTable->catid == $categoryId) {
                    continue;
                }

                // Store original data for potential rollback
                $originalData = clone $articleTable;
                $articleTable->catid = $categoryId;

                // Preserve modified data
                $articleTable->modified = $originalData->modified;
                $articleTable->modified_by = $originalData->modified_by;

                if (!$articleTable->store()) {
                    $app->enqueueMessage('Failed to update article ID ' . $pk . ': ' . $articleTable->getError(), 'error');
                    $errors++;
                    continue;
                }

                $processed++;

            } catch (Exception $e) {
                $app->enqueueMessage('Error processing article ' . $pk . ': ' . $e->getMessage(), 'error');
                $errors++;
            }
        }

        if ($errors > 0) {
            $app->enqueueMessage(sprintf('Encountered errors with %d articles', $errors), 'warning');
        }

    }


    public function onContentBeforeSave(): void
    {
        // future
    }
}
