<?php
/**
 * Artbambou SmileCustomEntityWidget Module
 *
 * @category  Artbambou
 * @package   Artbambou_SmileCustomEntityWidget
 * @author    Ilan Parmentier
 * @copyright Copyright (c) 2025 Amadeco
 * @license   Proprietary License
 */
declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Controller\Adminhtml\Entity\Widget;

use Artbambou\SmileCustomEntityWidget\Block\Adminhtml\Entity\Widget\Chooser as ChooserBlock;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\LayoutFactory;

/**
 * Admin controller for the Custom Entity chooser grid.
 *
 * Handles AJAX requests from the widget condition editor to render
 * and reload the entity chooser grid. This is the equivalent of
 * Magento\CatalogWidget\Controller\Adminhtml\Product\Widget\Conditions\Chooser
 * for Smile Custom Entities.
 *
 * Route: custom_entity_widget/entity_widget/chooser
 */
class Chooser extends Action
{
    /**
     * ACL resource for widget management.
     *
     * @see \Magento\Widget\Controller\Adminhtml\Widget\Instance
     */
    public const ADMIN_RESOURCE = 'Magento_Widget::widget_instance';

    /**
     * @param Context       $context       Backend action context
     * @param RawFactory    $resultRawFactory Raw result factory for HTML output
     * @param LayoutFactory $layoutFactory Layout factory for block rendering
     */
    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly LayoutFactory $layoutFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Render the custom entity chooser grid HTML.
     *
     * Creates the chooser grid block, injects the unique ID from
     * the request (used to bind the grid to the correct form element),
     * pre-selects any already chosen entities, and returns raw HTML.
     *
     * @return Raw
     */
    public function execute(): Raw
    {
        $uniqId = (string) $this->getRequest()->getParam('uniq_id', '');
        $selectedParam = $this->getRequest()->getParam('selected', '');

        $layout = $this->layoutFactory->create();

        /** @var ChooserBlock $chooserBlock */
        $chooserBlock = $layout->createBlock(ChooserBlock::class);

        /** @var Raw $resultRaw */
        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setContents($chooserBlock->toHtml());

        return $resultRaw;
    }
}