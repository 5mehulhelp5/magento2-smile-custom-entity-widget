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

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Context;
use Psr\Log\LoggerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Api\Data\CustomEntityInterface;
use Smile\CustomEntity\Model\CustomEntity\Attribute as CustomEntityAttribute;
use Smile\ScopedEav\Api\Data\EntityInterface;

/**
 * Rule condition for Smile Custom Entity attributes.
 *
 * Handles mapping of Rule Conditions to SearchCriteria filters
 * for Smile Custom Entity repository queries.
 *
 * Extends AbstractCondition directly to avoid pulling in irrelevant
 * Catalog Product dependencies (ProductFactory, ProductResource, etc.).
 */
class Entity extends AbstractCondition implements ResetAfterRequestInterface
{
    /**
     * Form element name for widget parameters.
     *
     * @var string
     */
    protected $elementName = 'parameters';

    /**
     * List of attribute codes to exclude from condition options.
     *
     * @var string[]
     */
    private array $excludeAttributes = [];

    /**
     * Operator input types mapping.
     *
     * Maps input types to their allowed operators for the condition UI.
     *
     * @var array<string, string[]>|null
     */
    private ?array $operatorInputByType = null;

    /**
     * Array input types that accept multiple values.
     *
     * @var string[]
     */
    private array $arrayInputTypes = ['multiselect'];

    /**
     * @param Context $context
     * @param EavConfig $eavConfig
     * @param FilterBuilder $filterBuilder
     * @param LoggerInterface $logger
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly EavConfig $eavConfig,
        private readonly FilterBuilder $filterBuilder,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get default operator input by type mapping.
     *
     * Defines which comparison operators are available for each attribute input type.
     *
     * @return array<string, string[]>
     */
    public function getDefaultOperatorInputByType(): array
    {
        if ($this->operatorInputByType === null) {
            $this->operatorInputByType = [
                'string'      => ['==', '!=', '{}', '!{}', '()', '!()'],
                'numeric'     => ['==', '!=', '>=', '>', '<=', '<'],
                'date'        => ['==', '>=', '>', '<=', '<'],
                'select'      => ['==', '!='],
                'boolean'     => ['==', '!='],
                'multiselect' => ['()', '!()'],
            ];
        }

        return $this->operatorInputByType;
    }

    /**
     * Get default operator options for the current input type.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getDefaultOperatorOptions(): array
    {
        $operatorsByType = $this->getDefaultOperatorInputByType();
        $inputType = $this->getInputType();
        $allowedOperators = $operatorsByType[$inputType] ?? $operatorsByType['string'];

        $allOperators = [
            '=='  => __('is'),
            '!='  => __('is not'),
            '>='  => __('equals or greater than'),
            '<='  => __('equals or less than'),
            '>'   => __('greater than'),
            '<'   => __('less than'),
            '{}'  => __('contains'),
            '!{}' => __('does not contain'),
            '()'  => __('is one of'),
            '!()' => __('is not one of'),
        ];

        $options = [];
        foreach ($allowedOperators as $operator) {
            if (isset($allOperators[$operator])) {
                $options[] = ['value' => $operator, 'label' => $allOperators[$operator]];
            }
        }

        return $options;
    }

    /**
     * Retrieve the operator select element HTML.
     *
     * @return string
     */
    public function getOperatorElementHtml(): string
    {
        return $this->getOperatorElement()->getHtml();
    }

    /**
     * Get the operator select form element.
     *
     * Overridden to use our custom operator options based on input type.
     *
     * @return \Magento\Framework\Data\Form\Element\AbstractElement
     */
    public function getOperatorElement(): \Magento\Framework\Data\Form\Element\AbstractElement
    {
        $element = parent::getOperatorElement();
        $element->setValues($this->getDefaultOperatorOptions());

        return $element;
    }

    /**
     * Retrieve the Custom Entity EAV attribute object for this condition.
     *
     * Handles loading from the custom entity EAV type and fixes missing
     * source models on multiselect attributes.
     *
     * @return AbstractAttribute|CustomEntityAttribute|null
     */
    public function getAttributeObject(): AbstractAttribute|CustomEntityAttribute|null
    {
        $code = $this->getAttribute();

        if (!$code) {
            return null;
        }

        try {
            $attribute = $this->eavConfig->getAttribute(
                CustomEntityAttributeInterface::ENTITY_TYPE_CODE,
                $code
            );

            // Ensure multiselect attributes have a source model for option retrieval
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
     * Load available attribute options for the condition dropdown.
     *
     * Populates the attribute selector with all eligible Custom Entity EAV attributes,
     * plus virtual/special attributes (entity_id, has_image).
     *
     * @return $this
     */
    public function loadAttributeOptions(): self
    {
        $attributeList = $this->eavConfig
            ->getEntityType(CustomEntityAttributeInterface::ENTITY_TYPE_CODE)
            ->getAttributeCollection()
            ->addFieldToFilter('frontend_label', ['neq' => ''])
            ->addFieldToFilter('attribute_code', ['nin' => $this->excludeAttributes]);

        $attributes = [];
        $this->addSpecialAttributes($attributes);

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
     * Collect validated attributes and apply them to the SearchCriteriaBuilder.
     *
     * Called by the Combine condition to build the full filter set.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function collectValidatedAttributes($searchCriteriaBuilder): self
    {
        return $this->addToSearchCriteria($searchCriteriaBuilder);
    }

    /**
     * Add this condition as a filter to the SearchCriteriaBuilder.
     *
     * Dispatches to specialized filter methods based on attribute type.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function addToSearchCriteria(SearchCriteriaBuilder $searchCriteriaBuilder): self
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

        $value = $this->normalizeConditionValue($value, $operator, $attribute);

        if ($value === null || $value === '' || $value === []) {
            return $this;
        }

        if ($attribute->getFrontendInput() === 'multiselect') {
            $this->applyMultiselectFilter($searchCriteriaBuilder, $code, $value, $operator);
        } else {
            $this->applyStandardFilter($searchCriteriaBuilder, $code, $value, $operator);
        }

        return $this;
    }

    /**
     * Build a standalone Filter object for this condition.
     *
     * Used by the Combine condition for OR (any) aggregation,
     * where filters need to be grouped without immediately adding to the builder.
     *
     * @return Filter|null
     */
    public function buildFilter(): ?Filter
    {
        $code = $this->getAttribute();
        $attribute = $this->getAttributeObject();

        if (!$code) {
            return null;
        }

        if ($code === 'has_image') {
            $value = (bool) $this->getValueParsed();

            return $this->filterBuilder
                ->setField(EntityInterface::IMAGE)
                ->setConditionType($value ? 'notnull' : 'null')
                ->setValue($value)
                ->create();
        }

        if (!$attribute) {
            return null;
        }

        $value = $this->getValueParsed();
        $operator = $this->getOperatorType();
        $value = $this->normalizeConditionValue($value, $operator, $attribute);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (in_array($operator, ['like', 'nlike']) && is_string($value)) {
            $value = str_replace(['%', '_'], ['\%', '\_'], $value);
            $value = '%' . $value . '%';
        }

        return $this->filterBuilder
            ->setField($code)
            ->setConditionType($operator)
            ->setValue(is_array($value) ? implode(',', $value) : $value)
            ->create();
    }

    /**
     * Map the rule UI operator symbol to a SearchCriteria condition type.
     *
     * @return string
     */
    public function getOperatorType(): string
    {
        $operator = $this->getOperator();

        return match ($operator) {
            '>'   => 'gt',
            '>='  => 'gte',
            '<'   => 'lt',
            '<='  => 'lte',
            '=='  => 'eq',
            '!='  => 'neq',
            '()'  => 'in',
            '!()' => 'nin',
            '{}'  => 'like',
            '!{}' => 'nlike',
            default => $this->isArrayOperatorType() ? 'in' : 'eq',
        };
    }

    /**
     * Determine the input type for the current attribute.
     *
     * Controls which operators and value elements are shown in the condition UI.
     *
     * @return string
     */
    public function getInputType(): string
    {
        if ($this->getAttribute() === 'has_image') {
            return 'select';
        }

        if ($this->getAttribute() === 'entity_id') {
            return 'string';
        }

        $attributeObject = $this->getAttributeObject();

        if (!$attributeObject) {
            return 'string';
        }

        return match ($attributeObject->getFrontendInput()) {
            'select'      => 'select',
            'multiselect' => 'multiselect',
            'date'        => 'date',
            'boolean'     => 'boolean',
            default       => 'string',
        };
    }

    /**
     * Determine the value element type for the current attribute.
     *
     * Controls the form element rendered for the condition value (select, text, date, etc.).
     *
     * @return string
     */
    public function getValueElementType(): string
    {
        if ($this->getAttribute() === 'has_image') {
            return 'select';
        }

        if ($this->getAttribute() === 'entity_id') {
            return 'text';
        }

        $attributeObject = $this->getAttributeObject();

        if (!$attributeObject) {
            return 'text';
        }

        return match ($attributeObject->getFrontendInput()) {
            'select', 'boolean' => 'select',
            'multiselect'       => 'multiselect',
            'date'              => 'date',
            default             => 'text',
        };
    }

    /**
     * Check if the current operator expects array values.
     *
     * @return bool
     */
    private function isArrayOperatorType(): bool
    {
        $operator = $this->getOperator();

        return in_array($operator, ['()', '!()']);
    }

    /**
     * Validate the model against this condition.
     *
     * Required by AbstractCondition. Always returns true since filtering
     * is handled at the SearchCriteria/repository level, not in-memory.
     *
     * @param AbstractModel $model
     * @return bool
     */
    public function validate(AbstractModel $model): bool
    {
        $attribute = $this->getAttributeObject();

        if ($attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $attributeValue = $model->getData($attributeCode);

            if ($attribute->getFrontendInput() === 'multiselect' && is_string($attributeValue)) {
                $attributeValue = explode(',', $attributeValue);
                $model->setData($attributeCode, $attributeValue);
            }
        }

        return parent::validate($model);
    }

    /**
     * Add virtual/special attributes to the attribute options list.
     *
     * @param array<string, \Magento\Framework\Phrase|string> &$attributes
     * @return void
     */
    private function addSpecialAttributes(array &$attributes): void
    {
        $attributes['entity_id'] = __('Entity ID');
        $attributes['has_image'] = __('Entity has image');
    }

    /**
     * Prepare value options for select and multiselect attributes.
     *
     * Loads available options from the attribute source model and caches
     * them on this condition instance for rendering.
     *
     * @return $this
     */
    protected function _prepareValueOptions(): self
    {
        if ($this->getData('value_select_options') && $this->getData('value_option')) {
            return $this;
        }

        $selectOptions = null;

        if ($this->getAttribute() === 'has_image') {
            $selectOptions = [
                ['value' => 0, 'label' => __('No')],
                ['value' => 1, 'label' => __('Yes')],
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
     * Apply filter for the virtual 'has_image' attribute.
     *
     * Filters entities that have (or don't have) an image set.
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
     * Apply specialized filtering logic for multiselect attributes.
     *
     * Multiselect values are stored as comma-separated strings in the database.
     * Uses FIND_IN_SET for individual value matching within these fields.
     *
     * - "Is one of" (IN):     OR group using finset per value
     * - "Is NOT one of" (NIN): AND group using nfinset per value
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param string $code Attribute code
     * @param mixed $value Condition value(s)
     * @param string $operator SearchCriteria condition type
     * @return void
     */
    private function applyMultiselectFilter(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $code,
        mixed $value,
        string $operator
    ): void {
        // "Is one of" (IN) → (Contains A) OR (Contains B)
        if ($operator === 'in' && is_array($value)) {
            $filters = [];

            foreach ($value as $item) {
                $filters[] = $this->filterBuilder
                    ->setField($code)
                    ->setConditionType('finset')
                    ->setValue($item)
                    ->create();
            }

            if (!empty($filters)) {
                $searchCriteriaBuilder->addFilters($filters);
            }

            return;
        }

        // "Is NOT one of" (NIN) → (Not Contains A) AND (Not Contains B)
        if ($operator === 'nin' && is_array($value)) {
            foreach ($value as $item) {
                $searchCriteriaBuilder->addFilter($code, $item, 'nfinset');
            }

            return;
        }

        // Fallback for single values or other operators
        $conditionType = match ($operator) {
            'eq'  => 'finset',
            'neq' => 'nfinset',
            default => $operator,
        };

        $searchCriteriaBuilder->addFilter($code, $value, $conditionType);
    }

    /**
     * Apply standard filtering logic for non-multiselect attributes.
     *
     * Handles LIKE operator escaping for wildcard characters.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param string $code Attribute code
     * @param mixed $value Condition value
     * @param string $operator SearchCriteria condition type
     * @return void
     */
    private function applyStandardFilter(
        SearchCriteriaBuilder $searchCriteriaBuilder,
        string $code,
        mixed $value,
        string $operator
    ): void {
        if (in_array($operator, ['like', 'nlike']) && is_string($value)) {
            $value = str_replace(['%', '_'], ['\%', '\_'], $value);
            $value = '%' . $value . '%';
        }

        $searchCriteriaBuilder->addFilter($code, $value, $operator);
    }

    /**
     * Normalize the condition value based on operator expectations and attribute type.
     *
     * Handles:
     * - Splitting comma-separated strings into arrays for array operators (in, nin)
     * - Flattening arrays to scalars for non-array operators
     * - Casting to integer for select/multiselect option IDs
     *
     * @param mixed $value Raw condition value
     * @param string $operator SearchCriteria condition type
     * @param AbstractAttribute|CustomEntityAttribute $attribute EAV attribute
     * @return mixed Normalized value
     */
    private function normalizeConditionValue(
        mixed $value,
        string $operator,
        AbstractAttribute|CustomEntityAttribute $attribute
    ): mixed {
        // Convert comma-separated strings to arrays for array-based operators
        if (in_array($operator, ['in', 'nin']) && !is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string) $value)));

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
     * Reset internal state after request processing.
     *
     * Implements ResetAfterRequestInterface for long-running processes.
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->operatorInputByType = null;
    }
}
