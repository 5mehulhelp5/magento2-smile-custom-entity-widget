<?php

declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Model\Rule\Condition;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean as BooleanSource;
use Magento\Eav\Model\Entity\Attribute\Source\Table as TableSource;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Rule\Model\Condition\Context;
use Psr\Log\LoggerInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Model\CustomEntity\Attribute as CustomEntityAttribute;
use Smile\ScopedEav\Api\Data\EntityInterface;

/**
 * Custom Entity Rule Condition.
 *
 * This class handles the validation and filtering logic for Custom Entity attributes
 * within the widget condition tree. It supports standard EAV attributes,
 * special "has_image" checks, and direct "entity_id" selection.
 */
class Entity extends AbstractCondition implements ResetAfterRequestInterface
{
    /**
     * @var string Form element name for the condition.
     */
    protected $elementName = 'parameters';

    /**
     * @var array|null Cached operator input types.
     */
    protected ?array $_operatorInputByType = null;

    /**
     * @var array Attributes to exclude from the condition list.
     */
    private array $excludeAttributes = [];

    /**
     * @var array Input types that support array values.
     */
    private array $arrayInputTypes = ['multiselect'];

    /**
     * @var AbstractAttribute|CustomEntityAttribute|null|false Cached attribute object.
     */
    private AbstractAttribute|CustomEntityAttribute|null|false $cachedAttributeObject = false;

    /**
     * @var string|null Cached attribute code.
     */
    private ?string $cachedAttributeCode = null;

    /**
     * Constructor.
     *
     * @param Context $context Rule context.
     * @param BackendHelper $backendHelper Backend helper for URLs.
     * @param EavConfig $eavConfig EAV configuration.
     * @param FilterBuilder $filterBuilder Builder for API filters.
     * @param LoggerInterface $logger Logger for error tracking.
     * @param AssetRepository $assetRepository Repository for static assets.
     * @param array $data Additional data.
     */
    public function __construct(
        Context $context,
        private readonly BackendHelper $backendHelper,
        private readonly EavConfig $eavConfig,
        private readonly FilterBuilder $filterBuilder,
        private readonly LoggerInterface $logger,
        private readonly AssetRepository $assetRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Retrieve the input type for operators (string, numeric, date, etc.).
     *
     * @return array
     */
    public function getOperatorInputByType(): array
    {
        return $this->getDefaultOperatorInputByType();
    }

    /**
     * Define default operators available for each input type.
     *
     * @return array
     */
    public function getDefaultOperatorInputByType(): array
    {
        if ($this->_operatorInputByType === null) {
            $this->_operatorInputByType = [
                'string'      => ['==', '!=', '{}', '!{}', '()', '!()'],
                'numeric'     => ['==', '!=', '>=', '>', '<=', '<'],
                'date'        => ['==', '>=', '>', '<=', '<'],
                'select'      => ['==', '!='],
                'boolean'     => ['==', '!='],
                'multiselect' => ['()', '!()'],
                'grid'        => ['()', '!()'],
            ];
        }

        return $this->_operatorInputByType;
    }

    /**
     * Get available operators as select options.
     *
     * @return array
     */
    public function getOperatorSelectOptions(): array
    {
        return $this->getDefaultOperatorOptions();
    }

    /**
     * Get the translated name of the current operator.
     *
     * @return string
     */
    public function getOperatorName(): string
    {
        $operator = $this->getOperator();
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

        return (string) ($allOperators[$operator] ?? $operator);
    }

    /**
     * Get default operator options based on input type.
     *
     * @return array
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
     * Render the HTML for the operator selector.
     *
     * @return string
     */
    public function getOperatorElementHtml(): string
    {
        return $this->getOperatorElement()->getHtml();
    }

    /**
     * Configure the operator element with allowed values.
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
     * Retrieve the EAV attribute object for the current condition.
     *
     * @return AbstractAttribute|CustomEntityAttribute|null
     */
    public function getAttributeObject(): AbstractAttribute|CustomEntityAttribute|null
    {
        $code = $this->getAttribute();

        if (!$code || $code === 'has_image' || $code === 'entity_id') {
            return null;
        }

        if ($this->cachedAttributeObject !== false && $this->cachedAttributeCode === $code) {
            return $this->cachedAttributeObject;
        }

        $this->cachedAttributeCode = $code;

        try {
            $attribute = $this->eavConfig->getAttribute(
                CustomEntityAttributeInterface::ENTITY_TYPE_CODE,
                $code
            );

            if (!$attribute || !$attribute->getId()) {
                $this->cachedAttributeObject = null;
                return null;
            }

            // Ensure source models are set for specific input types to prevent errors
            $frontendInput = $attribute->getFrontendInput();
            if ($frontendInput === 'multiselect' && !$attribute->getData('source_model')) {
                $attribute->setData('source_model', TableSource::class);
            }
            if ($frontendInput === 'boolean' && !$attribute->getData('source_model')) {
                $attribute->setData('source_model', BooleanSource::class);
            }

            $this->cachedAttributeObject = $attribute;
            return $attribute;
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('CustomEntityWidget: Error loading attribute "%s". %s', $code, $e->getMessage())
            );
        }

        $this->cachedAttributeObject = null;
        return null;
    }

    /**
     * Load attribute options for the condition dropdown.
     *
     * Filters attributes and adds special attributes like entity_id.
     *
     * @return self
     */
    public function loadAttributeOptions(): self
    {
        $attributeList = $this->eavConfig->getEntityType(CustomEntityAttributeInterface::ENTITY_TYPE_CODE)
            ->getAttributeCollection()
            ->addFieldToFilter('frontend_label', ['neq' => '']);

        if (!empty($this->excludeAttributes)) {
            $attributeList->addFieldToFilter('attribute_code', ['nin' => $this->excludeAttributes]);
        }

        $attributes = [];
        // Add special custom attributes
        $this->addSpecialAttributes($attributes);

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
     * Add special attributes (non-EAV) to the list.
     *
     * @param array $attributes
     * @return void
     */
    private function addSpecialAttributes(array &$attributes): void
    {
        $attributes['entity_id'] = __('Entity ID');
        $attributes['has_image'] = __('Entity has image');
    }

    /**
     * Get value option by key.
     *
     * @param string|null $option
     * @return mixed
     */
    public function getValueOption($option = null)
    {
        $this->_prepareValueOptions();
        return $this->getData('value_option' . ($option !== null ? '/' . $option : ''));
    }

    /**
     * Get all value options.
     *
     * @return array
     */
    public function getValueSelectOptions()
    {
        $this->_prepareValueOptions();
        return $this->getData('value_select_options');
    }

    /**
     * Get the human-readable name of the selected value.
     *
     * Used for the "Label" in the conditions tree.
     *
     * @return string
     */
    public function getValueName(): string
    {
        $value = $this->getValue();

        if ($value === null || $value === '' || $value === false) {
            return '...';
        }

        $this->_prepareValueOptions();
        $options = $this->getData('value_option');

        if (!is_array($options)) {
            $options = [];
        }

        if (is_array($value)) {
            $valueNames = [];
            foreach ($value as $val) {
                $valueNames[] = $options[$val] ?? $val;
            }
            return implode(', ', $valueNames);
        }

        if (is_string($value) && str_contains($value, ',')) {
            $valueNames = [];
            foreach (explode(',', $value) as $val) {
                $val = trim($val);
                $valueNames[] = $options[$val] ?? $val;
            }
            return implode(', ', $valueNames);
        }

        return (string) ($options[$value] ?? $value);
    }

    /**
     * Prepare options for select/multiselect attributes.
     *
     * @return self
     */
    protected function _prepareValueOptions(): self
    {
        $selectReady = $this->getData('value_select_options');
        $hashedReady = $this->getData('value_option');

        if (is_array($selectReady) && !empty($selectReady)
            && is_array($hashedReady) && !empty($hashedReady)
        ) {
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
                try {
                    $source = $attributeObject->getSource();
                    if ($source) {
                        $selectOptions = $source->getAllOptions($addEmptyOption, true);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        sprintf(
                            'CustomEntityWidget: Failed to load source for attribute "%s". %s',
                            $this->getAttribute(),
                            $e->getMessage()
                        )
                    );
                }
            }

            // Fallback for boolean if no source found
            if (empty($selectOptions) && $attributeObject->getFrontendInput() === 'boolean') {
                $selectOptions = [
                    ['value' => '', 'label' => ' '],
                    ['value' => 0, 'label' => __('No')],
                    ['value' => 1, 'label' => __('Yes')],
                ];
            }
        }

        $this->setSelectOptions($selectOptions, $selectReady, $hashedReady);

        return $this;
    }

    /**
     * Set the prepared options into the data object.
     *
     * @param array|null $selectOptions
     * @param mixed $selectReady
     * @param mixed $hashedReady
     * @return self
     */
    private function setSelectOptions(?array $selectOptions, mixed $selectReady, mixed $hashedReady): self
    {
        if ($selectOptions !== null) {
            if (!$selectReady) {
                $this->setData('value_select_options', $selectOptions);
            }
            if (!$hashedReady) {
                $hashedOptions = [];
                foreach ($selectOptions as $option) {
                    if (!is_array($option) || !array_key_exists('value', $option)) {
                        continue;
                    }
                    if (is_array($option['value'])) {
                        continue;
                    }
                    $hashedOptions[$option['value']] = $option['label'] ?? '';
                }
                $this->setData('value_option', $hashedOptions);
            }
        } else {
            if (!is_array($selectReady)) {
                $this->setData('value_select_options', []);
            }
            if (!is_array($hashedReady)) {
                $this->setData('value_option', []);
            }
        }

        return $this;
    }

    /**
     * Determine the input type for the value field.
     *
     * @return string
     */
    public function getInputType(): string
    {
        if ($this->getAttribute() === 'has_image') {
            return 'select';
        }

        if ($this->getAttribute() === 'entity_id') {
            return 'grid'; // Changed to 'grid' to indicate special handling, though acts like string
        }

        $attributeObject = $this->getAttributeObject();
        if (!$attributeObject || !$attributeObject->getFrontendInput()) {
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
     * Determine the element type for rendering.
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
     * Get the URL for the chooser popup.
     *
     * @return string
     */
    public function getValueElementChooserUrl(): string
    {
        if ($this->getAttribute() !== 'entity_id') {
            return '';
        }

        // Pass 'form' parameter so the grid knows which input ID to update
        return $this->backendHelper->getUrl(
            'custom_entity_widget/entity_widget/chooser',
            [
                'form' => $this->getJsFormObject(),
                'uniq_id' => $this->getId()
            ]
        );
    }

    /**
     * Render the chooser trigger icon after the input element.
     *
     * @return string
     */
    public function getValueAfterElementHtml(): string
    {
        if ($this->getAttribute() !== 'entity_id') {
            return '';
        }

        $image = $this->assetRepository->getUrl('images/rule_chooser_trigger.gif');

        // This structure matches what Magento's rules.js expects for a chooser trigger
        return '<a href="javascript:void(0)" class="rule-chooser-trigger">'
            . '<img src="' . $image . '" alt="" class="v-middle rule-chooser-trigger" '
            . 'title="' . __('Open Chooser') . '" /></a>';
    }

    /**
     * Check if the condition requires explicit application (like a grid chooser).
     *
     * @return bool
     */
    public function getExplicitApply(): bool
    {
        return $this->getAttribute() === 'entity_id';
    }

    /**
     * Check if the operator supports array values (IN, NOT IN).
     *
     * @return bool
     */
    public function isArrayOperatorType(): bool
    {
        $operator = $this->getOperator();
        return in_array($operator, ['()', '!()']);
    }

    /**
     * Collect attributes and add them to the search criteria builder.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return self
     */
    public function collectValidatedAttributes($searchCriteriaBuilder): self
    {
        return $this->addToSearchCriteria($searchCriteriaBuilder);
    }

    /**
     * Apply the filter to the search criteria.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return self
     */
    public function addToSearchCriteria(SearchCriteriaBuilder $searchCriteriaBuilder): self
    {
        $code = $this->getAttribute();
        if (!$code) {
            return $this;
        }

        if ($code === 'has_image') {
            return $this->applyHasImageFilter($searchCriteriaBuilder);
        }

        if ($code === 'entity_id') {
            return $this->applyEntityIdFilter($searchCriteriaBuilder);
        }

        $attribute = $this->getAttributeObject();
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
     * Parse the value, splitting comma-separated strings for array operators.
     *
     * @return mixed
     */
    public function getValueParsed()
    {
        if (!$this->hasValueParsed()) {
            $value = $this->getData('value');
            if ($this->isArrayOperatorType() && is_string($value)) {
                $value = preg_split('#\s*[,;]\s*#', $value, -1, PREG_SPLIT_NO_EMPTY);
            }
            $this->setValueParsed($value);
        }
        return $this->getData('value_parsed');
    }

    /**
     * Convert condition operator to API Filter Condition Type.
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
     * Validate a model against the condition (used for PHP-side validation).
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

            // Normalize multiselect string values to arrays for validation
            if ($attribute->getFrontendInput() === 'multiselect' && is_string($attributeValue)) {
                $attributeValue = explode(',', $attributeValue);
                $model->setData($attributeCode, $attributeValue);
            }
        }

        return parent::validate($model);
    }

    /**
     * Determine if the 'has_image' check expects a true or false result.
     *
     * @return bool
     */
    private function resolveHasImageIntent(): bool
    {
        $selectedValue = (bool) (int) $this->getValueParsed();
        $isNegated = $this->getOperator() === '!=';
        return $isNegated ? !$selectedValue : $selectedValue;
    }

    /**
     * Apply 'has_image' filter to SearchCriteria.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return self
     */
    private function applyHasImageFilter(SearchCriteriaBuilder $searchCriteriaBuilder): self
    {
        $entityMustHaveImage = $this->resolveHasImageIntent();
        $searchCriteriaBuilder->addFilter(
            EntityInterface::IMAGE,
            'true',
            $entityMustHaveImage ? 'notnull' : 'null'
        );

        return $this;
    }

    /**
     * Apply 'entity_id' filter to SearchCriteria.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return self
     */
    private function applyEntityIdFilter(SearchCriteriaBuilder $searchCriteriaBuilder): self
    {
        $value = $this->getValueParsed();
        $operator = $this->getOperatorType();

        if (!is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string) $value)));
        }

        if (empty($value)) {
            return $this;
        }

        // Force 'in' or 'nin' for entity ID arrays if operator is simple equality
        if ($operator === 'eq') {
            $operator = 'in';
        }
        if ($operator === 'neq') {
            $operator = 'nin';
        }

        $this->applyStandardFilter($searchCriteriaBuilder, 'entity_id', $value, $operator);
        return $this;
    }

    /**
     * Apply filters for multiselect attributes.
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

        if ($operator === 'nin' && is_array($value)) {
            foreach ($value as $item) {
                $searchCriteriaBuilder->addFilter($code, $item, 'nfinset');
            }
            return;
        }

        $conditionType = match ($operator) {
            'eq'  => 'finset',
            'neq' => 'nfinset',
            default => $operator,
        };

        $searchCriteriaBuilder->addFilter($code, $value, $conditionType);
    }

    /**
     * Apply standard scalar filters.
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
        if (in_array($operator, ['like', 'nlike']) && is_string($value)) {
            $value = str_replace(['%', '_'], ['\\%', '\\_'], $value);
            $value = '%' . $value . '%';
        }

        $searchCriteriaBuilder->addFilter($code, $value, $operator);
    }

    /**
     * Normalize values based on operator and attribute type.
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
        if ($value === false) {
            return null;
        }

        if (in_array($operator, ['in', 'nin']) && !is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string) $value)));
            if (empty($value)) {
                return null;
            }
        }

        // For non-array operators, ensure we have a scalar
        if (is_array($value) && !in_array($operator, ['in', 'nin'])) {
            $value = reset($value);
        }

        $frontendInput = $attribute->getFrontendInput();
        if (in_array($frontendInput, ['select', 'multiselect', 'boolean'])) {
            if (is_array($value)) {
                $value = array_map('intval', $value);
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            }
        }

        return $value;
    }

    /**
     * Reset state after request to prevent data leakage in loop contexts.
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->_operatorInputByType = null;
        $this->cachedAttributeObject = false;
        $this->cachedAttributeCode = null;
    }
}