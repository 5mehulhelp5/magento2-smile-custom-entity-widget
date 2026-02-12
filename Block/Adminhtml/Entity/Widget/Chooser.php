<?php

declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Block\Adminhtml\Entity\Widget;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Grid\Extended as GridExtended;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Model\ResourceModel\CustomEntity\CollectionFactory as CustomEntityCollectionFactory;

/**
 * Grid Chooser Block for Smile Custom Entities.
 *
 * Designed to behave like the Native Product Widget Chooser.
 * It handles multi-selection of entities via a grid popup and updates
 * the parent Rule Condition input field using jQuery.
 *
 * @api
 */
class Chooser extends GridExtended
{
    /**
     * Default suffix for the grid ID to prevent collisions.
     */
    private const DEFAULT_GRID_SUFFIX = 'entityCondition';

    /**
     * Constructor.
     *
     * @param Context $context Template context.
     * @param BackendHelper $backendHelper Backend helper.
     * @param CustomEntityCollectionFactory $collectionFactory Factory for Custom Entity collection.
     * @param EavConfig $eavConfig EAV Config to retrieve attribute sets.
     * @param array<string, mixed> $data Additional block data.
     */
    public function __construct(
        Context $context,
        BackendHelper $backendHelper,
        protected readonly CustomEntityCollectionFactory $collectionFactory,
        protected readonly EavConfig $eavConfig,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $backendHelper,
            $data
        );
    }

    /**
     * Initialize the grid settings.
     *
     * Sets the unique ID, sorting defaults, and enables AJAX.
     *
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();

        $uniqId = $this->getRequest()->getParam('uniq_id');
        if (empty($uniqId)) {
            $uniqId = $this->getId() ?: self::DEFAULT_GRID_SUFFIX;
        }

        // Set a unique ID for the grid to avoid JS conflicts in the DOM
        $this->setId('customEntityChooserGrid_' . $uniqId);
        $this->setDefaultSort('entity_id');
        $this->setDefaultDir('ASC');
        $this->setUseAjax(true);
        $this->setDefaultFilter(['chooser_is_active' => '1']);
    }

    /**
     * Retrieve the URL for grid operations (sorting, filtering, pagination).
     *
     * @return string
     */
    public function getGridUrl(): string
    {
        $currentId = $this->getId();
        $suffix = str_replace('customEntityChooserGrid_', '', $currentId);

        return $this->getUrl(
            'custom_entity_widget/entity_widget/chooser',
            [
                'uniq_id'  => $suffix ?: self::DEFAULT_GRID_SUFFIX,
                'selected' => $this->getRequest()->getParam('selected', ''),
                'form'     => $this->getRequest()->getParam('form', ''),
                '_current' => true,
            ]
        );
    }

    /**
     * Prepare the entity collection for the grid.
     *
     * Filters by attribute set if provided in the request.
     *
     * @return self
     */
    protected function _prepareCollection(): self
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('name');
        $collection->addAttributeToSelect('is_active');

        // Filter by Attribute Set ID if passed from the widget configuration
        $attributeSetId = (int) $this->getRequest()->getParam('attribute_set_id', 0);
        if ($attributeSetId > 0) {
            $collection->addFieldToFilter('attribute_set_id', $attributeSetId);
        }

        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    /**
     * Prepare the columns for the grid.
     *
     * @return self
     * @throws \Exception
     */
    protected function _prepareColumns(): self
    {
        $this->addColumn(
            'chooser_id',
            [
                'header'           => __('Select'),
                'type'             => 'checkbox',
                'name'             => 'chooser_id',
                'values'           => $this->getSelectedEntityIds(),
                'index'            => 'entity_id',
                'header_css_class' => 'col-select col-massaction',
                'column_css_class' => 'col-select col-massaction',
                'align'            => 'center',
            ]
        );

        $this->addColumn(
            'entity_id',
            [
                'header'           => __('ID'),
                'sortable'         => true,
                'type'             => 'number',
                'index'            => 'entity_id',
                'header_css_class' => 'col-id',
                'column_css_class' => 'col-id',
            ]
        );

        $this->addColumn(
            'chooser_name',
            [
                'header'           => __('Name'),
                'index'            => 'name',
                'header_css_class' => 'col-name',
                'column_css_class' => 'col-name',
            ]
        );

        $this->addColumn(
            'chooser_attribute_set',
            [
                'header'           => __('Attribute Set'),
                'index'            => 'attribute_set_id',
                'type'             => 'options',
                'options'          => $this->getAttributeSetOptions(),
                'header_css_class' => 'col-attr-set',
                'column_css_class' => 'col-attr-set',
            ]
        );

        $this->addColumn(
            'chooser_is_active',
            [
                'header'           => __('Status'),
                'index'            => 'is_active',
                'type'             => 'options',
                'options'          => [
                    '0' => __('Disabled'),
                    '1' => __('Enabled'),
                ],
                'header_css_class' => 'col-status',
                'column_css_class' => 'col-status',
            ]
        );

        return parent::_prepareColumns();
    }

    /**
     * Retrieve the currently selected entity IDs from the request.
     *
     * @return array<int>
     */
    public function getSelectedEntityIds(): array
    {
        $selected = $this->getRequest()->getParam('selected', '');
        if (is_array($selected) && !empty($selected)) {
            return $selected;
        }
        if (is_string($selected) && trim($selected) === '') {
            return [];
        }
        return array_map('intval', array_filter(explode(',', $selected)));
    }

    /**
     * Retrieve available attribute sets for the Custom Entity type.
     *
     * @return array<int, string>
     */
    private function getAttributeSetOptions(): array
    {
        $options = [];
        try {
            $entityType = $this->eavConfig->getEntityType(
                CustomEntityAttributeInterface::ENTITY_TYPE_CODE
            );
            $setCollection = $entityType->getAttributeSetCollection();
            foreach ($setCollection as $attributeSet) {
                $options[$attributeSet->getId()] = $attributeSet->getAttributeSetName();
            }
        } catch (LocalizedException) {
            // Ignore exception if entity type doesn't exist (unlikely in this context)
        }

        return $options;
    }

    /**
     * Generate the JavaScript callback for row clicks using jQuery.
     *
     * Logic:
     * 1. Uses jQuery for reliable DOM traversal.
     * 2. Finds the parent LI using .closest().
     * 3. Selects strictly the text input to avoid other fields.
     *
     * @return string JavaScript code
     */
    /**
     * Generate the JavaScript callback for row clicks using jQuery.
     */
    public function getRowClickCallback(): string
    {
        return '
            function (grid, event) {
                var $ = jQuery;
                var trElement = $(Event.findElement(event, "tr"));
                var eventElement = $(Event.element(event));
                var isInput = eventElement.is("input") || eventElement.is("select") || eventElement.is("option");

                // 1. Toggle Checkbox
                if (trElement.length) {
                    var checkbox = trElement.find("input[type=\'checkbox\']");
                    if (checkbox.length && !isInput) {
                        checkbox.prop("checked", !checkbox.prop("checked"));
                    }
                }

                // 2. Aggregate Values
                var $gridContainer = $("#" + grid.containerId);
                var values = [];

                // --- CORRECTION ICI ---
                // On cible les checkbox cochées à l\'intérieur du tbody pour éviter les headers
                // Et on ne filtre plus par name="chooser_id" car il est vide dans le HTML
                $gridContainer.find("tbody tr .col-chooser_id input[type=\'checkbox\']:checked").each(function() {
                     values.push($(this).val());
                });

                // 3. Find parent LI
                var $li = $gridContainer.closest("li");

                if ($li.length) {
                    // 4. Update Input
                    var $input = $li.find("input.element-value-changer[type=\'text\']");

                    if (!$input.length) {
                        $input = $li.find("input.input-text[type=\'text\']");
                    }

                    if ($input.length) {
                        $input.val(values.join(","));
                        $input.trigger("change");

                        // Native event trigger
                        var domInput = $input.get(0);
                        if (domInput && "createEvent" in document) {
                            var evt = document.createEvent("HTMLEvents");
                            evt.initEvent("change", false, true);
                            domInput.dispatchEvent(evt);
                        }
                    }
                }
            }
        ';
    }

    /**
     * Retrieve the callback function for checkbox clicks.
     *
     * @return string
     */
    public function getCheckboxCheckCallback(): string
    {
        return $this->getRowClickCallback();
    }
}