<?php

declare(strict_types=1);

namespace DamCommon\Services;

use Espo\Core\Utils\Json;
use Treo\Core\Container;

/**
 * Class MigrationPimImage
 * @package DamCommon\Services
 */
class PimLayout
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * PimLayout constructor.
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Call all methods for modify layouts
     */
    public function modify()
    {
        $methods = get_class_methods(self::class);
        foreach ($methods as $method) {
            //call modify methods
            if ($method !== 'modify' && strpos($method, 'modify') !== false) {
                $this->{$method}();
            }
        }
    }

    /**
     * @param array $values
     * @param string $scope
     * @param string $layout
     */
    public function setLayout(array $values, string $scope, string $layout)
    {
        $this->container->get('layout')->set($values, $scope, $layout);
        $this->container->get('layout')->save();
    }

    /**
     * @param $scope
     * @return array
     */
    public function getLayout(string $scope, string $layout): array
    {
        return Json::decode($this->container->get('layout')->get($scope, $layout), true);
    }

    /**
     * Modify Product Relationships
     */
    protected function modifyProductRelationships()
    {
        $items = $this->getLayout('Product', 'relationships');
        if (!empty($this->container->get('metadata')->get('entityDefs.Product.links.assets'))
            && !$this->isExistInDetail('asset_relations', $items)) {
            $items[] = 'asset_relations';
            $this->setLayout($items, 'Product', 'relationships');
        }
    }

    /**
     * Modify Category Relationships
     */
    protected function modifyCategoryRelationships()
    {
        $items = $this->getLayout('Category', 'relationships');
        if (!empty($this->container->get('metadata')->get('entityDefs.Category.links.assets'))
            && !$this->isExistInDetail('asset_relations', $items)) {
            $items[] = 'asset_relations';
            $this->setLayout($items, 'Category', 'relationships');
        }
    }

    /**
     * Modify AssociatedProduct DetailSmall
     */
    protected function modifyAssociatedProductDetailSmall()
    {
        $rows = $this->getLayout('AssociatedProduct', 'detailSmall');

        if ($this->isExistField('AssociatedProduct', 'mainProductImage')
            && $this->isExistField('AssociatedProduct', 'relatedProductImage')
            && !$this->isExistInDetail('mainProductImage', $rows[0]['rows'])
            && !$this->isExistInDetail('relatedProductImage', $rows[0]['rows'])) {
            $rows[0]['rows'][] = [
                [
                    "name" => "mainProductImage"
                ],
                [
                    "name" => "relatedProductImage"
                ]
            ];
            $this->setLayout($rows, 'AssociatedProduct', 'detailSmall');
        }
    }

    /**
     * Modify AssociatedProduct List
     */
    protected function modifyProductList()
    {
        $columns = $this->getLayout('Product', 'list');
        if ($this->isExistField('Product', 'image') && !$this->isExistInList('image', $columns)) {
            $columns = $this->setAfterFieldInList('image', 'name', $columns);
            $this->setLayout($columns, 'Product', 'list');
        }
    }

    /**
     * Modify AssociatedProduct List
     */
    protected function modifyAssociatedProductList()
    {
        $columns = $this->getLayout('AssociatedProduct', 'list');
        if ($this->isExistField('AssociatedProduct', 'mainProductImage')
            && $this->isExistField('AssociatedProduct', 'relatedProductImage')
            && !$this->isExistInList('mainProductImage', $columns)
            && !$this->isExistInList('relatedProductImage', $columns)) {
            $columns = $this
                ->setAfterFieldInList('mainProductImage', 'mainProduct', $columns, ['notSortable' => true]);
            $columns = $this
                ->setAfterFieldInList('relatedProductImage', 'relatedProduct', $columns, ['notSortable' => true]);

            $this->setLayout($columns, 'AssociatedProduct', 'list');
        }
    }

    /**
     * Modify AssociatedProduct ListSmall
     */
    protected function modifyAssociatedProductListSmall()
    {
        $columns = $this->getLayout('AssociatedProduct', 'listSmall');
        if ($this->isExistField('AssociatedProduct', 'relatedProductImage')
            && !$this->isExistInList('relatedProductImage', $columns)) {
            $first = array_shift($columns);
            array_unshift($columns, $first, ['name' => 'relatedProductImage']);
        }
        $this->setLayout($columns, 'AssociatedProduct', 'listSmall');
    }

    /**
     * Modify Category List
     */
    protected function modifyCategoryList()
    {
        $columns = $this->getLayout('Category', 'list');
        if ($this->isExistField('Category', 'image') && !$this->isExistInList('image', $columns)) {
            $columns = $this->setAfterFieldInList('image', 'name', $columns);
        }
        $this->setLayout($columns, 'Category', 'list');
    }

    /**
     * @param string $field
     * @param string $afterField
     * @param array $columns
     * @param array $data
     * @return array
     */
    protected function setAfterFieldInList(string $field, string $afterField, array $columns, array $data = []): array
    {
        for ($k = 0; $k < count($columns); $k++) {
            if ($columns[$k]['name'] == $afterField) {
                //put new row
                array_splice($columns, ++$k, 0, [array_merge(['name' => $field], $data)]);
                break;
            }
        }

        return $columns;
    }

    /**
     * @param string $name
     * @param array $columns
     * @return bool
     */
    protected function isExistInList(string $name, array $columns): bool
    {
        $isExist = false;
        foreach ($columns as $column) {
            if (!empty($column['name']) && $column['name'] === $name) {
                $isExist = true;
            }
        }

        return $isExist;
    }

    /**
     * @param string $name
     * @param array $rows
     * @return bool
     */
    protected function isExistInDetail(string $name, array $rows): bool
    {
        return $this->recursiveArraySearch($name, $rows);
    }

    /**
     * @param string $scope
     * @param string $field
     * @return bool
     */
    protected function isExistField(string $scope, string $field): bool
    {
        return !empty($this->container->get('metadata')->get("entityDefs.{$scope}.fields.{$field}"));
    }

    /**
     * @param $needle
     * @param $haystack
     * @return bool|int|string
     */
    protected function recursiveArraySearch($needle, $haystack): bool
    {
        foreach ($haystack as $key => $value) {
            if ($needle === $value || (is_array($value) && $this->recursiveArraySearch($needle, $value) !== false)) {
                return true;
            }
        }
        return false;
    }
}
