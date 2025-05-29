<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.category
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Workflow\Category\Extension;

use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Event\View\DisplayEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
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
final class Category extends CMSPlugin implements SubscriberInterface
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
            'onAfterDisplay'             => 'onAfterDisplay',
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
            $this->enhanceWorkflowTransitionForm($form, $data);

            return;
        }

        if ($context === 'com_content.article') {
            if (empty($data && $data->id)) {
                return;
            }
            $this->disableCategoryField($form, $data);
        }
    }

    /**
     * Manipulate the generic list view
     *
     * @param   DisplayEvent  $event
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function onAfterDisplay(DisplayEvent $event)
    {
        if (!$this->getApplication()->isClient('administrator')) {
            return;
        }

        $js = "
            document.addEventListener('DOMContentLoaded', function()
            {
                var dropdown = document.getElementById('toolbar-status-group');
                if (!dropdown){
                    return;
                }
                var batchButton = document.getElementById('status-group-children-batch');
                if (batchButton){
                    batchButton.addEventListener('click', function()
                    {
                        var observer = new MutationObserver(function(mutations, observer) {
                            var categorySelector = document.getElementById('batch-category-id');
                            if (categorySelector) {
                                categorySelector.disabled = true;
                                observer.disconnect();
                            }
                        });

                        observer.observe(document.body, { childList: true, subtree: true });
                    });
                }
            });
        ";

        $this->getApplication()->getDocument()->addScriptDeclaration($js);
    }

    /**
     * Check if the current plugin should execute workflow related activities
     *
     * @param   string  $context
     *
     * @return   boolean
     *
     * @since   DEPLOY_VERSION
     */
    protected function isSupported($context)
    {
        return in_array($context, ['com_workflow.transition', 'com_content.article']);
    }

    /**
     * Disable the category field in the article form.
     *
     * @param   Form      $form  The form
     * @param   \stdClass    $data  The data
     *
     * @return  void
     *
     * @since   DEPLOY_VERSION
     */
    protected function disableCategoryField(Form $form, $data)
    {
        // Get the current category ID value
        $catid = $form->getValue('catid');

        $form->setFieldAttribute('catid', 'readonly', 'true');
        $form->setFieldAttribute('catid', 'value', $catid);

        $js = "
            document.addEventListener('DOMContentLoaded', function() {
                var categoryField = document.getElementById('jform_catid');
                if (categoryField) {
                    categoryField.disabled = true;
                }
            });
        ";
        $this->getApplication()->getDocument()->addScriptDeclaration($js);
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
    public function onWorkflowAfterTransition(WorkflowTransitionEvent $event): void
    {
        $app = $this->getApplication();
        $pks = $event->getArgument('pks');
        $transition = $event->getArgument('transition');

        if (!is_object($transition) || !($transition->options instanceof Registry) || empty($transition->options)) {
            $app->enqueueMessage('PLG_WORKFLOW_CATEGORY_INVALID_TRANSITION');
            return;
        }

        $options = $transition->options;
        $categoryId = (int) $options->get('category_id');

        if (empty($pks) || !is_array($pks)) {
            $app->enqueueMessage(Text::_('PLG_WORKFLOW_CATEGORY_NO_PRIMARY_KEY'), 'error');
            return;
        }

        $errors = 0;

        foreach ($pks as $pk) {
            if (!self::processArticle($pk, $categoryId)) {
                $errors++;
            }
        }

        if ($errors > 0) {
            $app->enqueueMessage(Text::plural('PLG_WORKFLOW_CATEGORY_ERRORS', $errors), 'warning');
        }
    }

    /**
     * Process the article and update its category.
     *
     * @param   int                  $pk         The primary key
     * @param   int                  $categoryId The category ID
     *
     * @return  bool
     *
     * @since   DEPLOY_VERSION
     */
    private function processArticle($pk, $categoryId): bool
    {
        $app = $this->getApplication();
        $result = false;

        try {

            $component = $this->getApplication()->bootComponent('com_content');
            $modelName = $component->getModelName('com_workflow.article');

            $articleTable = $component->getMVCFactory()->createModel($modelName, $this->getApplication()->getName(), ['ignore_request' => true])
                ->getTable();
            if (!$articleTable->load($pk)) {
                $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_ARTICLE_NOT_FOUND', $pk), 'warning');
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
                    $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_ARTICLE_UPDATE_FAILED', $pk, $categoryId), 'error');
                } else {
                    $result = true;
                }
            }
        } catch (\RuntimeException $e) {
            $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_ARTICLE_UPDATE_ERROR', $pk) . ': ' . $e->getMessage(), 'error');
        }

        return $result;
    }
}
