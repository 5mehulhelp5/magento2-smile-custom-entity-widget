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
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @return $this
     */
    public function collectValidatedAttributes($searchCriteriaBuilder)
    {
        $isAny = $this->getAggregator() === 'any';
    
        if ($isAny) {
            $orFilters = [];
    
            foreach ($this->getConditions() as $condition) {
                if ($condition instanceof self) {
                    // Nested Combine: recurse (it will add its own groups)
                    $condition->collectValidatedAttributes($searchCriteriaBuilder);
                } elseif ($condition instanceof Entity) {
                    $filter = $condition->buildFilter();
                    if ($filter) {
                        $orFilters[] = $filter;
                    }
                }
            }
    
            // addFilters (plural) creates a single FilterGroup = OR
            if (!empty($orFilters)) {
                $searchCriteriaBuilder->addFilters($orFilters);
            }
        } else {
            // "all" aggregator: each condition adds its own AND group
            foreach ($this->getConditions() as $condition) {
                $condition->collectValidatedAttributes($searchCriteriaBuilder);
            }
        }
    
        return $this;
    }
}
