<?php
/**
 * Artbambou SmileCustomEntityWidget Module
 *
 * @category   Artbambou
 * @package    Artbambou_SmileCustomEntityWidget
 * @author     Ilan Parmentier
 */
declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Block\Set\Widget;

use Artbambou\SmileCustomEntityWidget\Model\Rule;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory as ElementFactory;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;
use Magento\Framework\Registry;
use Magento\Rule\Block\Conditions as RuleConditions;

/**
 * Entity Chooser for Smile Custom Entity
 */
class Conditions extends Template implements RendererInterface
{
    /**
     * Use the standard Magento conditions template
     *
     * @var string
     */
    protected $_template = 'Artbambou_SmileCustomEntityWidget::widget/conditions.phtml';

    /**
     * The element being rendered by this block
     *
     * @var AbstractElement
     */
    protected $element;

    /**
     * Internal element used to render conditions input
     *
     * @var \Magento\Framework\Data\Form\Element\Text
     */
    protected $input;

    /**
     * @param Context $context
     * @param ElementFactory $elementFactory
     * @param RuleConditions $conditions // Use alias
     * @param Rule $rule
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected readonly Rule $rule,
        protected readonly ElementFactory $elementFactory,
        protected readonly RuleConditions $conditions,
        protected readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function _construct()
    {
        $widgetParameters = [];
        $widget = $this->registry->registry('current_widget_instance');
        if ($widget) {
            $widgetParameters = $widget->getWidgetParameters();
        } elseif ($widgetOptions = $this->getLayout()->getBlock('wysiwyg_widget.options')) {
            $widgetParameters = $widgetOptions->getWidgetValues();
        }

        if (isset($widgetParameters['conditions'])) {
            $this->rule->loadPost($widgetParameters);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function render(AbstractElement $element)
    {
        $this->element = $element;

        $conditions = $this->rule->getConditions();
        $conditions->setJsFormObject($this->getHtmlId());

        return $this->toHtml();
    }

    /**
     * @return string
     */
    public function getNewChildUrl()
    {
        return $this->getUrl(
            'custom_entity_widget/set_widget/conditions',
            ['form' => $this->getHtmlId()]
        );
    }

    /**
     * Get the element this renderer is attached to.
     *
     * @return AbstractElement
     */
    public function getElement()
    {
        return $this->element;
    }

    /**
     * Get the HTML ID for the conditions container.
     *
     * @return string
     */
    public function getHtmlId()
    {
        return $this->getElement()->getContainer()->getHtmlId();
    }

    /**
     * Generate the HTML for the conditions input element.
     *
     * @return string
     */
    public function getInputHtml()
    {
        if (!$this->input) {
            $this->input = $this->elementFactory->create('text');
            $this->input->setRule($this->rule)
                ->setRenderer($this->conditions);

            if ($this->getElement() && $this->getElement()->getForm()) {
                $this->input->setForm($this->getElement()->getForm());
            }
        }
        return $this->input->toHtml();
    }
}