<?php
/**
 * Artbambou SmileCustomEntityWidget Module
 *
 * @category   Artbambou
 * @package    Artbambou_SmileCustomEntityWidget
 * @author     Ilan Parmentier
 */
declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Smile\ScopedEav\Model\AbstractEntity;

class SortBy implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'entity_id', 'label' => __('ID')],
            ['value' => AbstractEntity::CREATED_AT, 'label' => __('Created At')],
            ['value' => AbstractEntity::UPDATED_AT, 'label' => __('Updated At')],
            ['value' => AbstractEntity::NAME, 'label' => __('Name')]
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public static function toArray(): array
    {
        return [
            'entity_id' => __('ID'),
            AbstractEntity::CREATED_AT => __('Created At'),
            AbstractEntity::UPDATED_AT => __('Updated At'),
            AbstractEntity::NAME => __('Name')
        ];
    }
}
