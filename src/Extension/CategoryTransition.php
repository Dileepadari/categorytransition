<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.category_transition
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Workflow\CategoryTransition\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Workflow\WorkflowPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

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
     * @param   PrepareFormEvent  $event  The event
     *
     * @since   DEPLOY_VERSION
     */
    public function onContentPrepareForm(PrepareFormEvent $event)
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

        $options = $transition->options;
        $categoryId = (int) $options->get('category_id');

        if (empty($pks) || !is_array($pks)) {
            $app->enqueueMessage(Text::_('PLG_WORKFLOW_CATEGORY_TRANSITION_NO_PRIMARY_KEY'), 'error');
            return;
        }

        $errors = 0;

        foreach ($pks as $pk) {
            if (!self::processArticle($app, $pk, $categoryId)) {
                $errors++;
            }
        }

        if ($errors > 0) {
            $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_TRANSITION_ERROR', $errors), 'warning');
        }
    }

    /**
     * Validate the transition object.
     *
     * @param   CMSApplicationInterface  $app        The application object
     * @param   object               $transition The transition object
     *
     * @return  bool
     *
     * @since   DEPLOY_VERSION
     */
    private static function validateTransition($app, $transition): bool
    {
        if (!is_object($transition)) {
            $app->enqueueMessage(Text::_('PLG_WORKFLOW_CATEGORY_TRANSITION_INVALID_OBJECT_TYPE'), 'error');
            return false;
        }

        if (!($transition->options instanceof Registry)) {
            $app->enqueueMessage(Text::_('PLG_WORKFLOW_CATEGORY_TRANSITION_INVALID_REGISTRY'), 'error');
            return false;
        }

        if ($transition->options === null) {
            $app->enqueueMessage(Text::_('PLG_WORKFLOW_CATEGORY_TRANSITION_NO_OPTIONS'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process the article and update its category.
     *
     * @param   CMSApplicationInterface  $app        The application object
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
                $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_TRANSITION_ARTICLE_NOT_FOUND', $pk), 'warning');
                return false;
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
                    $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_TRANSITION_ARTICLE_UPDATE_FAILED', $pk, $categoryId) . ': ' . $articleTable->getError(), 'error');
                } else {
                    $result = true;
                }
            }
        } catch (\RuntimeException $e) {
            $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_TRANSITION_ARTICLE_UPDATE_ERROR', $pk) . ': ' . $e->getMessage(), 'error');
        }

        return $result;
    }
}
