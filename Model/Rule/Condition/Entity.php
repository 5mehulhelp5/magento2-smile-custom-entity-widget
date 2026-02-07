<?php
/**
 * Artbambou SmileCustomEntityWidget Module
 *
 * @category   Artbambou
 * @package    Artbambou_SmileCustomEntityWidget
 * @author     Ilan Parmentier
 */
declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Model\Rule\Condition;

use Magento\Backend\Helper\Data as BackendData;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductCategoryList;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection as AttributeSetCollection;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Rule\Model\Condition\Context;
use Magento\Rule\Model\Condition\Product\AbstractProduct;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntity\Model\CustomEntity\Attribute as CustomEntityAttribute;
use Smile\ScopedEav\Api\Data\EntityInterface;

/**
 * Rule smile custom entity condition data model.
 *
 * Handles mapping of Rule Conditions to SearchCriteria filters for Smile Custom Entities.
 */
class Entity extends AbstractProduct implements ResetAfterRequestInterface
{
    /**
     * @var string
     */
    protected $elementName = 'parameters';

    /**
     * @var array Attributes that have been joined to the collection
     */
    protected array $joinedAttributes = [];

    /**
     * List of attribute codes to exclude from condition options.
     *
     * @var string[]
     */
    private array $excludeAttributes = [
        CustomEntityInterface::URL_KEY,
        'image'
    ];

    /**
     * @param Context $context
     * @param BackendData $backendData
     * @param EavConfig $config
     * @param ProductFactory $productFactory
     * @param ProductRepositoryInterface $productRepository
     * @param ProductResource $productResource
     * @param AttributeSetCollection $attrSetCollection
     * @param FormatInterface $localeFormat
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param FilterBuilder $filterBuilder
     * @param array $data
     * @param ProductCategoryList|null $categoryList
     */
    public function __construct(
        Context $context,
        BackendData $backendData,
        EavConfig $config,
        ProductFactory $productFactory,
        ProductRepositoryInterface $productRepository,
        ProductResource $productResource,
        AttributeSetCollection $attrSetCollection,
        FormatInterface $localeFormat,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly LoggerInterface $logger,
        protected readonly FilterBuilder $filterBuilder,
        array $data = [],
        ?ProductCategoryList $categoryList = null
    ) {
        parent::__construct(
            $context,
            $backendData,
            $config,
            $productFactory,
            $productRepository,
            $productResource,
            $attrSetCollection,
            $localeFormat,
            $data,
            $categoryList
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultOperatorInputByType()
    {
        if (null === $this->_defaultOperatorInputByType) {
            parent::getDefaultOperatorInputByType();

            $this->_defaultOperatorInputByType = [
                'string'      => ['==', '!=', '{}', '!{}', '()', '!()'],
                'numeric'     => ['==', '!=', '>=', '>', '<=', '<'],
                'date'        => ['==', '>=', '>', '<=', '<'],
                'select'      => ['==', '!='],
                'boolean'     => ['==', '!='],
                'multiselect' => ['()', '!()']
            ];

            // Ensure multiselect is treated as an array input type by the UI
            $this->_arrayInputTypes[] = 'multiselect';
        }

        return $this->_defaultOperatorInputByType;
    }

    /**
     * Retrieve attribute object.
     *
     * Overridden to handle CustomEntity Attributes and fix missing source models.
     *
     * @return AbstractAttribute|CustomEntityAttribute|null
     */
    public function getAttributeObject()
    {
        $code = $this->getAttribute();
        
        try {
            $attribute = $this->_config->getAttribute(
                CustomEntityAttributeInterface::ENTITY_TYPE_CODE,
                $code
            );

            // Fix: Ensure multiselect attributes have a source model for option retrieval
            if ($attribute->getFrontendInput() === 'multiselect' && !$attribute->getData('source_model')) {
                $attribute->setData('source_model', TableSource::class);
            }

            return $attribute;
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('CustomEntityWidget: Error loading attribute "%s". %s', $code, $e->getMessage())
            );
        }

        return null;
    }

    /**
     * Load attribute options for the condition dropdown.
     *
     * @return $this
     */
    public function loadAttributeOptions(): self
    {
        $attributeList = $this->_config->getEntityType(CustomEntityAttributeInterface::ENTITY_TYPE_CODE)
            ->getAttributeCollection()
            ->addFieldToFilter('frontend_label', ['neq' => ''])
            ->addFieldToFilter('attribute_code', ['nin' => $this->excludeAttributes]);

        $attributes = [];
        $this->_addSpecialAttributes($attributes);

        /** @var AbstractAttribute $attribute */
        foreach ($attributeList as $attribute) {
            $attributes[$attribute->getAttributeCode()] = sprintf(
                '%s (%s)',
                $attribute->getFrontendLabel(),
                $attribute->getAttributeCode()
            );
        }

        asort($attributes);
        $this->setAttributeOption($attributes);

        return $this;
    }

    /**
     * Collect validated attributes for SearchCriteriaBuilder.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function collectValidatedAttributes($searchCriteriaBuilder): self
    {
        return $this->addToCollection($searchCriteriaBuilder);
    }

    /**
     * Add condition to SearchCriteriaBuilder.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function addToCollection(SearchCriteriaBuilder $searchCriteriaBuilder): self
    {
        $code = $this->getAttribute();
        $attribute = $this->getAttributeObject();

        if (!$code) {
            return $this;
        }

        if ($code === 'has_image') {
            return $this->applyHasImageFilter($searchCriteriaBuilder);
        }

        if (!$attribute) {
            return $this;
        }

        $value = $this->getValueParsed();
        $operator = $this->getOperatorType();

        // Normalize value based on operator expectations
        $value = $this->normalizeConditionValue($value, $operator, $attribute);
        
        // Skip empty values
        if ($value === null || $value === '' || $value === []) {
            return $this;
        }

        // Dispatch specific filter logic
        if ($attribute->getFrontendInput() === 'multiselect') {
            $this->applyMultiselectFilter($searchCriteriaBuilder, $code, $value, $operator);
        } else {
            $this->applyStandardFilter($searchCriteriaBuilder, $code, $value, $operator);
        }

        return $this;
    }

    /**
     * Apply filter for the virtual 'has_image' attribute.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    private function applyHasImageFilter(SearchCriteriaBuilder $searchCriteriaBuilder): self
    {
        $value = (bool) $this->getValueParsed();
        
        $searchCriteriaBuilder->addFilter(
            EntityInterface::IMAGE,
            $value,
            $value ? 'notnull' : 'null'
        );

        return $this;
    }

    /**
     * Apply specialized filtering logic for Multiselect attributes.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param string $code
     * @param mixed $value
     * @param string $operator
     * @return void
     */
    private function applyMultiselectFilter(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $code,
        mixed $value,
        string $operator
    ): void {
        // Case: "Is one of" (IN) -> Logic: (Contains A) OR (Contains B)
        if ($operator === 'in' && is_array($value)) {
            $filters = [];
            foreach ($value as $item) {
                $filters[] = $this->filterBuilder
                    ->setField($code)
                    ->setConditionType('finset')
                    ->setValue($item)
                    ->create();
            }
            // addFilters (plural) creates an OR group
            if (!empty($filters)) {
                $searchCriteriaBuilder->addFilters($filters);
            }
            return;
        }

        // Case: "Is NOT one of" (NIN) -> Logic: (Not Contains A) AND (Not Contains B)
        if ($operator === 'nin' && is_array($value)) {
            foreach ($value as $item) {
                // nfinset is supported by standard Magento Collection Processors
                $searchCriteriaBuilder->addFilter($code, $item, 'nfinset');
            }
            return;
        }

        // Fallback for single values or other operators
        $conditionType = match ($operator) {
            'eq' => 'finset',
            'neq' => 'nfinset',
            default => $operator
        };

        $searchCriteriaBuilder->addFilter($code, $value, $conditionType);
    }

    /**
     * Apply standard filtering logic.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param string $code
     * @param mixed $value
     * @param string $operator
     * @return void
     */
    private function applyStandardFilter(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $code,
        mixed $value,
        string $operator
    ): void {
        // Handle LIKE operator escaping
        if (in_array($operator, ['like', 'nlike']) && is_string($value)) {
            $value = str_replace(['%', '_'], ['\%', '\_'], $value);
            $value = '%' . $value . '%';
        }

        $searchCriteriaBuilder->addFilter($code, $value, $operator);
    }

    /**
     * Normalize the condition value based on the operator and attribute type.
     *
     * @param mixed $value
     * @param string $operator
     * @param AbstractAttribute|CustomEntityAttribute $attribute
     * @return mixed
     */
    private function normalizeConditionValue(
        mixed $value,
        string $operator,
        AbstractAttribute|CustomEntityAttribute $attribute
    ): mixed {
        // Convert comma-separated strings to arrays for array-based operators
        if (in_array($operator, ['in', 'nin']) && !is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string)$value)));
            if (empty($value)) {
                return null;
            }
        }
        
        // Flatten array if operator expects a scalar
        if (is_array($value) && !in_array($operator, ['in', 'nin'])) {
            $value = reset($value);
        }

        // Cast to int for numeric identifiers in select/multiselect
        $frontendInput = $attribute->getFrontendInput();
        if (in_array($frontendInput, ['select', 'multiselect'])) {
            if (is_array($value)) {
                $value = array_map('intval', $value);
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            }
        }

        return $value;
    }

    /**
     * Get the condition type map for SearchCriteriaBuilder.
     *
     * @return string
     */
    public function getOperatorType(): string
    {
        $operator = $this->getOperator();

        return match ($operator) {
            '>' => 'gt',
            '>=' => 'gte',
            '<' => 'lt',
            '<=' => 'lte',
            '()', '==' => 'in',
            '!()', '!=' => 'nin',
            '{}' => 'like',
            '!{}' => 'nlike',
            default => $this->isArrayOperatorType() ? 'in' : 'eq'
        };
    }

    /**
     * Add special attributes to the attribute list.
     *
     * @param array &$attributes
     * @return void
     */
    protected function _addSpecialAttributes(array &$attributes): void
    {
        $attributes['entity_id'] = __('Entity ID');
        $attributes['has_image'] = __('Entity has image');
    }

    /**
     * Retrieve input type for attribute.
     *
     * @return string
     */
    public function getInputType(): string
    {
        if ($this->getAttribute() === 'has_image') {
            return 'select';
        }
        
        if (!is_object($this->getAttributeObject())) {
            return 'string';
        }

        if ($this->getAttribute() === 'entity_id') {
            return 'string';
        }

        return match ($this->getAttributeObject()->getFrontendInput()) {
            'select' => 'select',
            'multiselect' => 'multiselect',
            'date' => 'date',
            'boolean' => 'boolean',
            default => 'string'
        };
    }

    /**
     * Retrieve value element type.
     *
     * @return string
     */
    public function getValueElementType(): string
    {
        if ($this->getAttribute() === 'has_image') {
            return 'select';
        }

        if (!is_object($this->getAttributeObject())) {
            return 'text';
        }

        if ($this->getAttribute() === 'entity_id') {
            return 'text';
        }

        return match ($this->getAttributeObject()->getFrontendInput()) {
            'select', 'boolean' => 'select',
            'multiselect' => 'multiselect',
            'date' => 'date',
            default => 'text',
        };
    }

    /**
     * Prepares value options to be used as select options or hashed array.
     *
     * @return $this
     */
    protected function _prepareValueOptions(): self
    {
        // Return early if options are already set
        if ($this->getData('value_select_options') && $this->getData('value_option')) {
            return $this;
        }

        $selectOptions = null;

        if ($this->getAttribute() === 'has_image') {
            $selectOptions = [
                ['value' => 0, 'label' => __('No')],
                ['value' => 1, 'label' => __('Yes')]
            ];
        } elseif (is_object($this->getAttributeObject())) {
            $attributeObject = $this->getAttributeObject();
            if ($attributeObject->usesSource()) {
                $addEmptyOption = $attributeObject->getFrontendInput() !== 'multiselect';
                $source = $attributeObject->getSource();
                if ($source) {
                    $selectOptions = $source->getAllOptions($addEmptyOption, true);
                }
            }
        }

        $this->setData('value_select_options', $selectOptions);
        
        if ($selectOptions) {
            $this->setData('value_option', array_column($selectOptions, 'label', 'value'));
        }

        return $this;
    }

    /**
     * Reset internal state after request.
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->joinedAttributes = [];
    }
}
