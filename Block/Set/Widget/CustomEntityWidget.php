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

use Artbambou\SmileCustomEntityWidget\Model\Config\Source\SortBy;
use Artbambou\SmileCustomEntityWidget\Model\Rule;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Magento\Widget\Helper\Conditions;
use Smile\CustomEntity\Api\CustomEntityRepositoryInterface;
use Smile\CustomEntity\Api\Data\CustomEntityAttributeInterface;
use Smile\CustomEntity\Block\CustomEntity\ImageFactory;
use Smile\CustomEntity\Block\Html\Pager;
use Smile\CustomEntity\Model\CustomEntity;

/**
 * Custom Entity Widget Block.
 *
 * Displays a filtered, sorted collection of Smile Custom Entities
 * based on widget parameters configured in the admin.
 *
 * Supports pagination, conditional filtering, sorting, and image display.
 */
class CustomEntityWidget extends Template implements BlockInterface, IdentityInterface
{
    /**
     * Default image width in pixels.
     */
    private const DEFAULT_IMAGE_WIDTH = 200;

    /**
     * Default image height in pixels.
     */
    private const DEFAULT_IMAGE_HEIGHT = 200;

    /**
     * Default number of items to display.
     */
    private const DEFAULT_ITEMS_COUNT = 8;

    /**
     * Default number of items per page when pagination is enabled.
     */
    private const DEFAULT_ITEMS_PER_PAGE = 4;

    /**
     * Default page variable name for pagination.
     */
    private const DEFAULT_PAGE_VAR_NAME = 'sp';

    /**
     * Default pager visibility.
     */
    private const DEFAULT_SHOW_PAGER = false;

    /**
     * Pager block instance.
     *
     * @var Pager|null
     */
    private ?Pager $pager = null;

    /**
     * Loaded attribute set instance.
     *
     * @var AttributeSetInterface|null
     */
    private ?AttributeSetInterface $attributeSet = null;

    /**
     * Loaded custom entity collection.
     *
     * @var \Smile\CustomEntity\Api\Data\CustomEntityInterface[]|null
     */
    private ?array $entities = null;

    /**
     * @param Template\Context $context
     * @param AttributeSetRepositoryInterface $attributeSetRepository
     * @param CustomEntityRepositoryInterface $customEntityRepository
     * @param EavConfig $eavConfig
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param SortOrderBuilder $sortOrderBuilder
     * @param Rule $rule
     * @param Conditions $conditionsHelper
     * @param ImageFactory $imageFactory
     * @param Json $serializer
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly CustomEntityRepositoryInterface $customEntityRepository,
        private readonly EavConfig $eavConfig,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly Rule $rule,
        private readonly Conditions $conditionsHelper,
        private readonly ImageFactory $imageFactory,
        private readonly Json $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Initialize block cache settings.
     *
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();

        $this->addData([
            'cache_lifetime' => 86400,
            'cache_tags' => [
                CustomEntity::CACHE_TAG,
            ],
        ]);
    }

    /**
     * Get key pieces for caching block content.
     *
     * @return array<int, string|int|null>
     * @SuppressWarnings(PHPMD.RequestAwareBlockMethod)
     */
    public function getCacheKeyInfo(): array
    {
        $conditions = $this->getData('conditions')
            ? $this->getData('conditions')
            : $this->getData('conditions_encoded');

        if (is_array($conditions)) {
            $conditions = $this->serializer->serialize($conditions);
        }

        return [
            'AB_CUSTOM_ENTITY_WIDGET',
            $this->_storeManager->getStore()->getId(),
            $this->_design->getDesignTheme()->getId(),
            $this->getImageWidth(),
            $this->getImageHeight(),
            $this->canShowFooterButton(),
            (int) $this->getRequest()->getParam($this->getPageVarName(), 1),
            $this->getItemsPerPage(),
            $this->getItemsCount(),
            $conditions,
            $this->serializer->serialize([
                $this->getPageVarName() => $this->getRequest()->getParam($this->getPageVarName())
            ]),
            $this->getTemplate(),
        ];
    }

    /**
     * Render block HTML only if entities are available.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if ($this->getEntities()) {
            return parent::_toHtml();
        }

        return '';
    }

    /**
     * Return filtered and sorted custom entities.
     *
     * Builds a SearchCriteria from widget parameters (attribute set, conditions,
     * sort order) and queries the repository. Handles pagination when enabled.
     *
     * @return \Smile\CustomEntity\Api\Data\CustomEntityInterface[]
     */
    public function getEntities(): array
    {
        if ($this->entities !== null) {
            return $this->entities;
        }
        
        $attributeSet = $this->getAttributeSet();
        if ($attributeSet === null) {
            $this->entities = [];
            return $this->entities;
        }

        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder */
        $searchCriteriaBuilder = $this->searchCriteriaBuilderFactory->create();

        $searchCriteriaBuilder->addFilter(
            'attribute_set_id',
            $attributeSet->getAttributeSetId()
        );

        $searchCriteriaBuilder->addFilter('is_active', true);

        $conditions = $this->getConditions();
        $conditions->collectValidatedAttributes($searchCriteriaBuilder);

        $sortOrder = $this->sortOrderBuilder
            ->setField($this->getSortBy())
            ->setDirection($this->getSortDirection())
            ->create();
        $searchCriteriaBuilder->setSortOrders([$sortOrder]);

        if ($this->showPager()) {
            $pager = $this->getPager();

            if ($pager !== null) {
                $pager->addCriteria($searchCriteriaBuilder);
                $searchResult = $this->customEntityRepository->getList($searchCriteriaBuilder->create());
                $pager->setSearchResult($searchResult);
            } else {
                $searchCriteriaBuilder->setPageSize($this->getItemsCount());
                $searchResult = $this->customEntityRepository->getList($searchCriteriaBuilder->create());
            }
        } else {
            $searchCriteriaBuilder->setPageSize($this->getItemsCount());
            $searchResult = $this->customEntityRepository->getList($searchCriteriaBuilder->create());
        }

        $this->entities = $searchResult->getItems();
    }

    /**
     * Return the attribute set used for filtering entities.
     *
     * Falls back to the default attribute set if none is configured
     * or if the configured attribute set no longer exists.
     *
     * @return AttributeSetInterface|null
     */
    public function getAttributeSet(): ?AttributeSetInterface
    {
        if ($this->attributeSet === null) {
            if ($this->hasData('attribute_set_id')) {
                $attributeSetId = (int) $this->getData('attribute_set_id');
            } else {
                $attributeSetId = $this->getDefaultAttributeSetId();
            }
    
            try {
                $this->attributeSet = $this->attributeSetRepository->get($attributeSetId);
            } catch (NoSuchEntityException $e) {
                $this->_logger->warning(
                    sprintf(
                        'CustomEntityWidget: Attribute set ID %d not found. Falling back to default.',
                        $attributeSetId
                    )
                );
    
                $defaultId = $this->getDefaultAttributeSetId();
    
                if ($defaultId === $attributeSetId) {
                    $this->_logger->error(
                        'CustomEntityWidget: Default attribute set could not be loaded. Widget will render empty.'
                    );
                    return null;
                }
    
                try {
                    $this->attributeSet = $this->attributeSetRepository->get($defaultId);
                } catch (NoSuchEntityException $e) {
                    $this->_logger->error(
                        sprintf(
                            'CustomEntityWidget: Default attribute set ID %d not found. Widget will render empty.',
                            $defaultId
                        )
                    );
                    return null;
                }
            }
        }
    
        return $this->attributeSet;
    }
    
    /**
     * Retrieve the default attribute set ID for the custom entity type.
     *
     * @return int
     */
    private function getDefaultAttributeSetId(): int
    {
        $entityType = $this->eavConfig->getEntityType(
            CustomEntityAttributeInterface::ENTITY_TYPE_CODE
        );
    
        return (int) $entityType->getDefaultAttributeSetId();
    }

    /**
     * Return the URL of the attribute set listing page.
     *
     * Uses the last loaded entity to determine the attribute set URL key.
     *
     * @return string
     */
    public function getAttributeSetUrl(): string
    {
        $entities = $this->getEntities();

        if (empty($entities)) {
            return '';
        }

        $customEntity = end($entities);

        return $this->_urlBuilder->getDirectUrl($customEntity->getAttributeSetUrlKey());
    }

    /**
     * Return the image HTML for a custom entity.
     *
     * @param \Smile\CustomEntity\Api\Data\CustomEntityInterface $entity
     * @return string
     */
    public function getImage($entity): string
    {
        return $this->imageFactory->create($entity)->toHtml();
    }

    /**
     * Return the frontend URL for a custom entity.
     *
     * @param \Smile\CustomEntity\Api\Data\CustomEntityInterface $entity
     * @return string
     */
    public function getEntityUrl($entity): string
    {
        return $this->_urlBuilder->getDirectUrl($entity->getUrlPath());
    }

    /**
     * Retrieve the total number of items to display.
     *
     * @return int
     */
    public function getItemsCount(): int
    {
        if (!$this->hasData('items_count') || $this->getData('items_count') === null) {
            $this->setData('items_count', self::DEFAULT_ITEMS_COUNT);
        }

        return (int) $this->getData('items_count');
    }

    /**
     * Retrieve the number of items per page.
     *
     * @return int
     */
    public function getItemsPerPage(): int
    {
        if (!$this->hasData('items_per_page')) {
            $this->setData('items_per_page', self::DEFAULT_ITEMS_PER_PAGE);
        }

        return (int) $this->getData('items_per_page');
    }

    /**
     * Determine whether the pager should be displayed.
     *
     * @return bool
     */
    public function showPager(): bool
    {
        if (!$this->hasData('show_pager')) {
            $this->setData('show_pager', self::DEFAULT_SHOW_PAGER);
        }

        return (bool) $this->getData('show_pager');
    }

    /**
     * Get the page variable name for pagination URL parameter.
     *
     * @return string
     */
    public function getPageVarName(): string
    {
        return $this->getData('page_var_name') ?: self::DEFAULT_PAGE_VAR_NAME;
    }

    /**
     * Retrieve the pager block instance.
     *
     * Creates and configures the pager block on first call.
     * Returns null if pagination is disabled.
     *
     * @return Pager|null
     */
    public function getPager(): ?Pager
    {
        if (!$this->showPager()) {
            return null;
        }

        if ($this->pager === null) {
            /** @var Pager $pager */
            $pager = $this->getLayout()->createBlock(
                Pager::class,
                $this->getWidgetPagerBlockName()
            );

            $pager->setUseContainer(true)
                ->setShowAmounts(true)
                ->setShowPerPage(false)
                ->setPageVarName($this->getPageVarName())
                ->setLimit($this->getItemsPerPage())
                ->setTotalLimit($this->getItemsCount());

            $this->pager = $pager;
        }

        return $this->pager;
    }

    /**
     * Render pagination HTML.
     *
     * @return string
     */
    public function getPagerHtml(): string
    {
        $pager = $this->getPager();

        if ($pager !== null) {
            return $pager->toHtml();
        }

        return '';
    }

    /**
     * Get the attribute code to sort entities by.
     *
     * Falls back to 'name' if the configured value is not valid.
     *
     * @return string
     */
    public function getSortBy(): string
    {
        $sortBy = $this->getData('sort_by');

        if (!in_array($sortBy, array_keys(SortBy::toArray()))) {
            $sortBy = 'name';
        }

        return $sortBy;
    }

    /**
     * Get the sort direction (ASC or DESC).
     *
     * @return string
     */
    public function getSortDirection(): string
    {
        $direction = SortOrder::SORT_DESC;

        if ($this->getData('sort_direction')) {
            $direction = strtoupper((string) $this->getData('sort_direction'));
        }

        if (!in_array($direction, [SortOrder::SORT_DESC, SortOrder::SORT_ASC])) {
            $direction = SortOrder::SORT_DESC;
        }

        return $direction;
    }

    /**
     * Get the configured image width.
     *
     * @return int
     */
    public function getImageWidth(): int
    {
        if ($this->hasData('image_width')) {
            return (int) $this->getData('image_width');
        }

        return self::DEFAULT_IMAGE_WIDTH;
    }

    /**
     * Get the configured image height.
     *
     * @return int
     */
    public function getImageHeight(): int
    {
        if ($this->hasData('image_height')) {
            return (int) $this->getData('image_height');
        }

        return self::DEFAULT_IMAGE_HEIGHT;
    }

    /**
     * Determine whether the footer button should be displayed.
     *
     * @return bool
     */
    public function canShowFooterButton(): bool
    {
        if ($this->hasData('show_footer_button')) {
            return (bool) $this->getData('show_footer_button');
        }

        return false;
    }

    /**
     * Get the footer button label text.
     *
     * @return string|\Magento\Framework\Phrase
     */
    public function getTextFooterButton(): string|\Magento\Framework\Phrase
    {
        if ($this->hasData('text_footer_button') && $this->getData('text_footer_button')) {
            return $this->getData('text_footer_button');
        }

        return __('Discover');
    }

    /**
     * Get the footer button title attribute.
     *
     * @return string|false
     */
    public function getTitleFooterButton(): string|false
    {
        if ($this->hasData('title_footer_button') && $this->getData('title_footer_button')) {
            return (string) $this->getData('title_footer_button');
        }

        return false;
    }

    /**
     * Decode and load the widget conditions into the Rule model.
     *
     * @return \Artbambou\SmileCustomEntityWidget\Model\Rule\Condition\Combine
     */
    public function getConditions(): \Artbambou\SmileCustomEntityWidget\Model\Rule\Condition\Combine
    {
        $conditions = $this->getData('conditions_encoded')
            ? $this->getData('conditions_encoded')
            : $this->getData('conditions');

        if (is_string($conditions)) {
            $conditions = $this->decodeConditions($conditions);
        }

        $this->rule->loadPost(['conditions' => $conditions]);

        return $this->rule->getConditions();
    }

    /**
     * Return block cache identity tags.
     *
     * Aggregates cache tags from all loaded entities and the attribute set.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $identities = [];

        if ($this->getEntities()) {
            foreach ($this->getEntities() as $entity) {
                $identities = array_merge($identities, $entity->getIdentities());
            }
        }

        $attributeSet = $this->getAttributeSet();
        if ($attributeSet !== null) {
            $identities[] = CustomEntity::CACHE_CUSTOM_ENTITY_SET_TAG . '_' . $attributeSet->getAttributeSetId();
        }

        return array_unique($identities);
    }

    /**
     * Generate the unique pager block name.
     *
     * @return string
     */
    private function getWidgetPagerBlockName(): string
    {
        $pageName = $this->getData('page_var_name');
        $pagerBlockName = 'widget.smile.set.list.pager';

        if (!$pageName) {
            return $pagerBlockName;
        }

        return $pagerBlockName . '.' . $pageName;
    }

    /**
     * Decode widget conditions from an encoded string.
     *
     * @param string $encodedConditions Conditions encoded by the widget helper.
     * @return array<mixed> Decoded conditions array.
     * @see \Magento\Widget\Model\Widget::getDirectiveParam
     */
    public function decodeConditions(string $encodedConditions): array
    {
        try {
            $conditions = $this->conditionsHelper->decode(htmlspecialchars_decode($encodedConditions));

            return is_array($conditions) ? $conditions : [];
        } catch (\InvalidArgumentException $exception) {
            $context = [
                'exception' => $exception,
                'encoded_conditions' => $encodedConditions,
                'uri' => $this->_request->getRequestUri(),
            ];
            $this->_logger->error($exception->getMessage(), $context);

            return [];
        }
    }
}
