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

use Artbambou\SmileCustomEntityWidget\Model\Rule\Condition\Entity;
use Artbambou\SmileCustomEntityWidget\Model\Rule\Condition\EntityFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Rule\Model\Condition\Context as RuleContext;
use Magento\Rule\Model\Condition\Combine as RuleCombine;

/**
 * Combination of product conditions
 */
class Combine extends RuleCombine
{
    /**
     * {@inheritdoc}
     */
    protected $elementName = 'parameters';

    /**
     * @var string
     */
    protected $type = 'Artbambou\SmileCustomEntityWidget\Model\Rule\Condition\Combine';

    /**
     * @var array
     */
    private $excludedAttributes;

    /**
     * @param RuleContext $context
     * @param EntityFactory $entityFactory
     * @param FilterBuilder $filterBuilder
     * @param array $data
     * @param array $excludedAttributes
     */
    public function __construct(
        RuleContext $context,
        protected readonly EntityFactory $entityFactory,
        protected readonly FilterBuilder $filterBuilder,
        array $data = [],
        array $excludedAttributes = []
    ) {
        parent::__construct($context, $data);
        
        $this->setType($this->type);
        $this->excludedAttributes = $excludedAttributes;
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        $entities = $this->entityFactory->create()->loadAttributeOptions()->getAttributeOption();
        $attributes = [];
        foreach ($entities as $code => $label) {
            if (!in_array($code, $this->excludedAttributes)) {
                $attributes[] = [
                    'value' => Entity::class . '|' . $code,
                    'label' => $label,
                ];
            }
        }
        $conditions = parent::getNewChildSelectOptions();
        $conditions = array_merge_recursive(
            $conditions,
            [
                [
                    'value' => \Artbambou\SmileCustomEntityWidget\Model\Rule\Condition\Combine::class,
                    'label' => __('Conditions Combination'),
                ],
                ['label' => __('Custom Entity Attribute'), 'value' => $attributes]
            ]
        );
        return $conditions;
    }

    /**
     * Collect validated attributes for Smile Custom Entity Collection.
     *
     * Respects the aggregator: "all" = AND (separate filter groups),
     * "any" = OR (single filter group via addFilters).
     *
     * For OR aggregation with multiselect attributes, each finset filter
     * is added individually to the OR group so that
     * (multiselect HAS 5) OR (multiselect HAS 8) OR (name = "Foo")
     * works correctly with FIND_IN_SET.
     *
     * For NIN (nfinset) in OR context: these are inherently AND-logic
     * ("must NOT contain A" AND "must NOT contain B"), so they are added
     * as separate filter groups to preserve correct semantics.
     *
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function collectValidatedAttributes($searchCriteriaBuilder)
    {
        $isAny = $this->getAggregator() === 'any';
    
        if ($isAny) {
            $orFilters = [];
            $andFilters = [];
    
            foreach ($this->getConditions() as $condition) {
                if ($condition instanceof self) {
                    // Nested Combine: recurse (adds its own filter groups)
                    $condition->collectValidatedAttributes($searchCriteriaBuilder);
                } elseif ($condition instanceof Entity) {
                    $filters = $condition->buildFilters();
    
                    if (empty($filters)) {
                        continue;
                    }
    
                    // nfinset filters must remain AND-joined even in OR context,
                    // because "NOT contains A AND NOT contains B" cannot be OR'd.
                    $this->partitionFilters($filters, $orFilters, $andFilters);
                }
            }
    
            // OR group: addFilters (plural) creates a single FilterGroup
            if (!empty($orFilters)) {
                $searchCriteriaBuilder->addFilters($orFilters);
            }
    
            // AND group: each nfinset filter gets its own FilterGroup
            foreach ($andFilters as $filter) {
                $searchCriteriaBuilder->addFilter(
                    $filter->getField(),
                    $filter->getValue(),
                    $filter->getConditionType()
                );
            }
        } else {
            // "all" aggregator: each condition adds its own AND group
            foreach ($this->getConditions() as $condition) {
                $condition->collectValidatedAttributes($searchCriteriaBuilder);
            }
        }
    
        return $this;
    }
    
    /**
     * Partition filters into OR-compatible and AND-only groups.
     *
     * nfinset (NOT FIND_IN_SET) conditions require AND semantics:
     * "NOT contains A" OR "NOT contains B" would match almost everything,
     * which is never the intended behavior. These must remain AND-joined.
     *
     * All other filter types (finset, eq, like, etc.) are safe for OR grouping.
     *
     * @param Filter[] $filters Source filters to partition
     * @param Filter[] &$orFilters Accumulator for OR-compatible filters
     * @param Filter[] &$andFilters Accumulator for AND-only filters (nfinset)
     * @return void
     */
    private function partitionFilters(array $filters, array &$orFilters, array &$andFilters): void
    {
        foreach ($filters as $filter) {
            if ($filter->getConditionType() === 'nfinset') {
                $andFilters[] = $filter;
            } else {
                $orFilters[] = $filter;
            }
        }
    }
}
