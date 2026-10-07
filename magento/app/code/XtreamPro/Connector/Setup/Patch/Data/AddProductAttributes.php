<?php
/**
 * Xtream UI Pro connector - product attributes (group "Xtream UI Pro" on the product form).
 */

namespace XtreamPro\Connector\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use XtreamPro\Connector\Model\Config\Source\Kind;
use XtreamPro\Connector\Model\Config\Source\Package;

class AddProductAttributes implements DataPatchInterface, PatchRevertableInterface
{
    const GROUP = 'Xtream UI Pro';

    const CODES = [
        'xtreampro_kind',
        'xtreampro_package_id',
        'xtreampro_trial',
        'xtreampro_credits',
    ];

    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    /** @var EavSetupFactory */
    private $eavSetupFactory;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, EavSetupFactory $eavSetupFactory)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eav */
        $eav = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Only simple and virtual products: one purchase = one or more units.
        $common = [
            'group'                   => self::GROUP,
            'global'                  => Attribute::SCOPE_GLOBAL,
            'visible'                 => true,
            'user_defined'            => true,
            'required'                => false,
            'visible_on_front'        => false,
            'used_in_product_listing' => false,
            'searchable'              => false,
            'filterable'              => false,
            'comparable'              => false,
            'apply_to'                => 'simple,virtual',
        ];

        $eav->addAttribute(Product::ENTITY, 'xtreampro_kind', $common + [
            'type'    => 'varchar',
            'label'   => 'Xtream UI Pro product type',
            'input'   => 'select',
            'source'  => Kind::class,
            'default' => '',
            'note'    => 'IPTV line creates one line per purchased unit. Sub-reseller account creates a reseller account for the customer (once) and hands over the credits below per unit. Registered customers only.',
            'sort_order' => 10,
        ]);
        $eav->addAttribute(Product::ENTITY, 'xtreampro_package_id', $common + [
            'type'    => 'int',
            'label'   => 'Xtream UI Pro package',
            'input'   => 'select',
            'source'  => Package::class,
            'note'    => 'IPTV lines only: the package of the line (loaded from the panel). Ignored for sub-reseller products.',
            'sort_order' => 20,
        ]);
        $eav->addAttribute(Product::ENTITY, 'xtreampro_trial', $common + [
            'type'    => 'int',
            'label'   => 'Trial line',
            'input'   => 'boolean',
            'source'  => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
            'default' => '0',
            'note'    => 'IPTV lines only: create a trial line (uses the package trial settings and trial credits).',
            'sort_order' => 30,
        ]);
        $eav->addAttribute(Product::ENTITY, 'xtreampro_credits', $common + [
            'type'    => 'int',
            'label'   => 'Credits per unit',
            'input'   => 'text',
            'class'   => 'validate-digits validate-zero-or-greater',
            'note'    => 'Sub-reseller products only: credits handed to the customer\'s account per purchased unit (taken from your reseller balance). 0 creates the account without credits.',
            'sort_order' => 40,
        ]);

        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    public function revert()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eav */
        $eav = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        foreach (self::CODES as $code) {
            $eav->removeAttribute(Product::ENTITY, $code);
        }
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
