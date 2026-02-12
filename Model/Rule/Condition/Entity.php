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
 * Custom Entity Rule Condition model.
 * Refactored to support complex "OR" logic via buildFilters.
 */
class Entity extends AbstractCondition implements ResetAfterRequestInterface
{
    /**
     * The name of the HTML element for the condition parameters.
     * * @var string
     */
    protected $elementName = 'parameters';

    /**
     * Mapping of input types to available operators.
     * * @var array|null
     */
    protected ?array $_operatorInputByType = null;

    /**
     * List of attribute codes to exclude from the condition selection.
     * * @var array
     */
    private array $excludeAttributes = [];

    /**
     * Internal tracking of input types that require array-based values.
     * * @var array
     */
    private array $arrayInputTypes = ['multiselect'];

    /**
     * Local cache for the loaded EAV attribute object.
     * * @var AbstractAttribute|CustomEntityAttribute|null|false
     */
    private AbstractAttribute|CustomEntityAttribute|null|false $cachedAttributeObject = false;

    /**
     * Local cache for the current attribute code to prevent redundant EAV lookups.
     * * @var string|null
     */
    private ?string $cachedAttributeCode = null;

    /**
     * @param Context $context
     * @param BackendHelper $backendHelper
     * @param EavConfig $eavConfig
     * @param FilterBuilder $filterBuilder
     * @param LoggerInterface $logger
     * @param AssetRepository $assetRepository
     * @param array $data
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
     * @inheritdoc
     */
    public function getOperatorInputByType(): array
    {
        return $this->getDefaultOperatorInputByType();
    }

    /**
     * @inheritdoc
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
     * @inheritdoc
     */
    public function getOperatorSelectOptions(): array
    {
        return $this->getDefaultOperatorOptions();
    }

    /**
     * @inheritdoc
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
     * @inheritdoc
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
     * @inheritdoc
     */
    public function getOperatorElementHtml(): string
    {
        return $this->getOperatorElement()->getHtml();
    }

    /**
     * @inheritdoc
     */
    public function getOperatorElement(): \Magento\Framework\Data\Form\Element\AbstractElement
    {
        $element = parent::getOperatorElement();
        $element->setValues($this->getDefaultOperatorOptions());
        return $element;
    }

    /**
     * Get attribute object.
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
     * Load attribute options.
     *
     * @return $this
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
     * Add special attributes (non-EAV).
     *
     * @param array $attributes
     */
    private function addSpecialAttributes(array &$attributes): void
    {
        $attributes['entity_id'] = __('Entity ID');
        $attributes['has_image'] = __('Entity has image');
    }

    /**
     * @inheritdoc
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
     * @inheritdoc
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
     * Prepare value options.
     *
     * @return $this
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
     * Set select options.
     *
     * @param array|null $selectOptions
     * @param mixed $selectReady
     * @param mixed $hashedReady
     * @return $this
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
     * @inheritdoc
     */
    public function getInputType(): string
    {
        if ($this->getAttribute() === 'has_image') {
            return 'select';
        }
        if ($this->getAttribute() === 'entity_id') {
            return 'grid';
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
     * @inheritdoc
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
     * @inheritdoc
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
     * @inheritdoc
     */
    public function getValueAfterElementHtml(): string
    {
        if ($this->getAttribute() !== 'entity_id') {
            return '';
        }

        $image = $this->assetRepository->getUrl('images/rule_chooser_trigger.gif');
        return '<a href="javascript:void(0)" class="rule-chooser-trigger">'
            . '<img src="' . $image . '" alt="" class="v-middle rule-chooser-trigger" '
            . 'title="' . __('Open Chooser') . '" /></a>';
    }

    /**
     * @inheritdoc
     */
    public function getExplicitApply(): bool
    {
        return $this->getAttribute() === 'entity_id';
    }

    /**
     * Check if operator is array type.
     *
     * @return bool
     */
    public function isArrayOperatorType(): bool
    {
        $operator = $this->getOperator();
        return in_array($operator, ['()', '!()']);
    }

    /**
     * Collect validated attributes.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function collectValidatedAttributes($searchCriteriaBuilder): self
    {
        return $this->addToSearchCriteria($searchCriteriaBuilder);
    }

    /**
     * Apply filters to search criteria builder.
     *
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function addToSearchCriteria(SearchCriteriaBuilder $searchCriteriaBuilder): self
    {
        $filters = $this->buildFilters();

        // If we have filters, apply them to the builder.
        // We use addFilter directly to ensure they are added to the current group (AND logic by default).
        // The Combine class handles OR logic by partitioning filters obtained via buildFilters.
        foreach ($filters as $filter) {
            $searchCriteriaBuilder->addFilter(
                $filter->getField(),
                $filter->getValue(),
                $filter->getConditionType()
            );
        }

        return $this;
    }

    /**
     * Build filters based on the current rule condition.
     *
     * This method is required by the Combine rule to process "ANY" (OR) aggregators.
     *
     * @return Filter[]
     */
    public function buildFilters(): array
    {
        $code = $this->getAttribute();
        if (!$code) {
            return [];
        }

        if ($code === 'has_image') {
            return $this->getHasImageFilters();
        }

        if ($code === 'entity_id') {
            return $this->getEntityIdFilters();
        }

        $attribute = $this->getAttributeObject();
        if (!$attribute) {
            return [];
        }

        $value = $this->getValueParsed();
        $operator = $this->getOperatorType();

        // Normalize value based on operator and attribute type
        $value = $this->normalizeConditionValue($value, $operator, $attribute);

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($attribute->getFrontendInput() === 'multiselect') {
            return $this->getMultiselectFilters($code, $value, $operator);
        }

        return $this->getStandardFilters($code, $value, $operator);
    }

    /**
     * @inheritdoc
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
     * Get mapped operator type for search criteria.
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
     * @inheritdoc
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
     * Resolve boolean intent for image check.
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
     * Get filters for "Has Image" condition.
     *
     * @return Filter[]
     */
    private function getHasImageFilters(): array
    {
        $entityMustHaveImage = $this->resolveHasImageIntent();

        return [
            $this->filterBuilder
                ->setField(EntityInterface::IMAGE)
                ->setValue('true')
                ->setConditionType($entityMustHaveImage ? 'notnull' : 'null')
                ->create()
        ];
    }

    /**
     * Get filters for Entity ID condition.
     *
     * @return Filter[]
     */
    private function getEntityIdFilters(): array
    {
        $value = $this->getValueParsed();
        $operator = $this->getOperatorType();

        if (!is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string) $value)));
        }

        if (empty($value)) {
            return [];
        }

        if ($operator === 'eq') {
            $operator = 'in';
        }
        if ($operator === 'neq') {
            $operator = 'nin';
        }

        return $this->getStandardFilters('entity_id', $value, $operator);
    }

    /**
     * Get filters for Multiselect attributes.
     *
     * @param string $code
     * @param mixed $value
     * @param string $operator
     * @return Filter[]
     */
    private function getMultiselectFilters(string $code, mixed $value, string $operator): array
    {
        $filters = [];

        if ($operator === 'in' && is_array($value)) {
            foreach ($value as $item) {
                $filters[] = $this->filterBuilder
                    ->setField($code)
                    ->setConditionType('finset')
                    ->setValue($item)
                    ->create();
            }
            return $filters;
        }

        if ($operator === 'nin' && is_array($value)) {
            foreach ($value as $item) {
                $filters[] = $this->filterBuilder
                    ->setField($code)
                    ->setConditionType('nfinset')
                    ->setValue($item)
                    ->create();
            }
            return $filters;
        }

        $conditionType = match ($operator) {
            'eq'  => 'finset',
            'neq' => 'nfinset',
            default => $operator,
        };

        $filters[] = $this->filterBuilder
            ->setField($code)
            ->setValue($value)
            ->setConditionType($conditionType)
            ->create();

        return $filters;
    }

    /**
     * Get standard filters.
     *
     * @param string $code
     * @param mixed $value
     * @param string $operator
     * @return Filter[]
     */
    private function getStandardFilters(string $code, mixed $value, string $operator): array
    {
        if (in_array($operator, ['like', 'nlike']) && is_string($value)) {
            $value = str_replace(['%', '_'], ['\\%', '\\_'], $value);
            $value = '%' . $value . '%';
        }

        return [
            $this->filterBuilder
                ->setField($code)
                ->setValue($value)
                ->setConditionType($operator)
                ->create()
        ];
    }

    /**
     * Normalize condition value.
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
     * @inheritdoc
     */
    public function _resetState(): void
    {
        $this->_operatorInputByType = null;
        $this->cachedAttributeObject = false;
        $this->cachedAttributeCode = null;
    }
}
