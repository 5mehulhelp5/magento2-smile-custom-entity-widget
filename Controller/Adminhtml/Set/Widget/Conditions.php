<?php
/**
 * Artbambou SmileCustomEntityWidget Module
 *
 * @category   Artbambou
 * @package    Artbambou_SmileCustomEntityWidget
 * @author     Ilan Parmentier
 */
declare(strict_types=1);

namespace Artbambou\SmileCustomEntityWidget\Controller\Adminhtml\Set\Widget;

use Artbambou\SmileCustomEntityWidget\Model\RuleFactory;
use Magento\Backend\App\Action\Context;
use Magento\CatalogWidget\Controller\Adminhtml\Product\Widget;
use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * @SuppressWarnings(PHPMD.AllPurposeAction)
 */
class Conditions extends Widget
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Magento_Widget::widget_instance';

    /**
     * @param Context $context
     * @param RuleFactory $ruleFactory
     */
    public function __construct(
        Context $context,
        protected readonly RuleFactory $ruleFactory
    ) {
        parent::__construct($context);
    }

    /**
     * Product widget conditions action
     *
     * @return void
     */
    public function execute()
    {
        $id = $this->getRequest()->getParam('id');
        $typeData = explode('|', str_replace('-', '/', $this->getRequest()->getParam('type', '')));
        $className = $typeData[0];
    
        // Validate the class is a legitimate condition
        if (!is_a($className, AbstractCondition::class, true)) {
            $this->getResponse()->setBody('');
            return;
        }
    
        $rule = $this->ruleFactory->create();
        $model = $this->_objectManager->create($className)
            ->setId($id)
            ->setType($className)
            ->setRule($rule)
            ->setPrefix('conditions');
    
        if (!empty($typeData[1])) {
            $model->setAttribute($typeData[1]);
        }
    
        if ($model instanceof AbstractCondition) {
            $model->setJsFormObject($this->getRequest()->getParam('form'));
            $result = $model->asHtmlRecursive();
        }
    
        $this->getResponse()->setBody($result ?? '');
    }
}
