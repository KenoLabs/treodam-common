<?php
declare(strict_types=1);

namespace DamCommon\Services;

use Dam\Entities\Collection;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Services\Record;
use Exception;
use PDO;
use stdClass;
use Treo\Composer\PostUpdate;
use Treo\Core\Utils\Auth;
use Treo\Core\Utils\Config;
use Treo\Core\Utils\Util;
use Treo\Services\AbstractService;
/**
 * Class MigrationPimImage
 * @package DamCommon\Services
 */
class MigrationPimImage extends AbstractService
{
    /**
     * @var array
     */
    protected $migratedAttachment = [];
    /**
     * @var string|null
     */
    protected $collectionId;
    /**
     * @throws Error
     */
    public function run(): void
    {
        sleep(10);
        (new Auth($this->getContainer()))->useNoAuth();
        // rebuild DB
        if(!$this->getContainer()->get('dataManager')->rebuild()) {
            sleep(10);
            $this->getContainer()->get('dataManager')->rebuild();
        }

        PostUpdate::renderLine('Getting PimImages');

        $attachments = $this->getAttachmentsForUp();
        $pimImageChannels = $this->getPimImageChannels();

        //for storing assetId with Channels
        $assetIdsWithChannel = '';
        $repAttachment = $this->getEntityManager()->getRepository('Attachment');

        PostUpdate::renderLine('Creating Assets');
        foreach ($attachments as $key => $attachment) {
            $id = $attachment['id'];
            $foreignName = !empty($attachment['product_id']) ? 'Product' : 'Category';
            $foreignId = !empty($attachment['product_id']) ? $attachment['product_id'] : $attachment['category_id'];
            if (empty($this->migratedAttachment[$id])) {
                $attachmentEntity = $this->getEntityManager()->getEntity('Attachment', $id);
                $attachmentEntity->set('relatedType', $foreignName);
                $pathFile = $repAttachment->getFilePath($attachmentEntity);
                if (empty($pathFile) || !file_exists($pathFile)) {
                    unset($attachments[$key]);
                    continue;
                }
                $this->updateAttachment($id, $attachment, $pathFile);
                //creating asset
                $foreign = !empty($attachment['product_id']) ? 'products' : 'categories';
                try {
                    $idAsset = $this->createAsset($id, $attachment['name'], $foreign, $foreignId, $attachment['assigned_user_id']);
                } catch (Exception $e) {
                    $this->setLog($id, $e);
                    continue;
                }
                if (!empty($pimImageChannels[$attachment['pimImage_id']])) {
                    $assetIdsWithChannel .= "'{$idAsset}',";
                }
                $this->migratedAttachment[$id] = $idAsset;
            } else {
                $scope = $attachment['scope'] === 'Channel' ? $attachment['scope'] : null;
                if (!empty($pimImageChannels[$attachment['pimImage_id']])) {
                    $assetIdsWithChannel .= "'{$this->migratedAttachment[$id]}',";
                }
                $this->insertAssetRelation($attachment, $foreignId, $foreignName, $id, $attachment['assigned_user_id'], $scope);
            }
        }
        PostUpdate::renderLine('Updating Scope');

        //remove last symbol(coma)
        $assetIdsWithChannel = substr($assetIdsWithChannel, 0, -1);
        if (!empty($assetIdsWithChannel)) {
            $this->setChannelScope($assetIdsWithChannel, 'Product');
            $this->setChannelScope($assetIdsWithChannel, 'Category');
            //create link asset_relation_channel
            PostUpdate::renderLine('Updating Channel Product');
            $this->insertAssetRelationChannel('Product', $assetIdsWithChannel);
            PostUpdate::renderLine('Updating Channel Category');
            $this->insertAssetRelationChannel('Category', $assetIdsWithChannel);
        }

        $this->getEntityManager()
            ->nativeQuery("UPDATE asset_relation SET scope = 'Global' WHERE scope IS NULL OR scope = '';");

        PostUpdate::renderLine('Updating Main Image');

        $this->updateMainImageUp('Product');
        $this->updateMainImageUp('Category');

        PostUpdate::renderLine('Removing pimImages');

        $this->getEntityManager()->nativeQuery('DROP TABLE pim_image;
                                                     DROP TABLE pim_image_channel;');
        if ($this->isVariants()) {
            PostUpdate::renderLine('Migrate Product Variants');

            $this->migrateUpVariants();
        }
    }

    /**
     * Migrate Variants
     */
    protected function migrateUpVariants(): void
    {
        $sqlReplace = "UPDATE product SET data = REPLACE(data, 'pimImages', 'asset_relations') WHERE type = 'productVariant' AND data LIKE '%pimImages%'";

        $this->getEntityManager()->nativeQuery($sqlReplace);

        $sqlMainImage = " 
                    UPDATE product AS v
                        LEFT JOIN product AS c ON v.configurable_product_id = c.id AND c.type = 'configurableProduct'
                                SET v.image_id = c.image_id
                            WHERE v.data NOT LIKE '%asset_relations%' AND v.type = 'productVariant'";

        $this->getEntityManager()->nativeQuery($sqlMainImage);
    }

    /**
     * @param array $attachment
     * @param string $foreignId
     * @param string $foreignName
     * @param string $id
     * @param string|null $assignedUserId
     * @param string|null $scope
     */
    protected function insertAssetRelation(array $attachment, string $foreignId, string $foreignName, string $id, ?string $assignedUserId, ?string $scope): void
    {
        $params = [
            'nameAsset' => (string)$attachment['name'],
            'foreignName' => $foreignName,
            'foreignId' => $foreignId,
            'assetId' => (string)$this->migratedAttachment[$id],
            'sortOrder' => $attachment['sort_order'],
            'assigned_user_id' => $assignedUserId,
            'scope' => $scope
        ];
        $this->getEntityManager()
            ->nativeQuery(
                "INSERT INTO asset_relation
                    (id, name, entity_name, entity_id, asset_id, sort_order, created_by_id, assigned_user_id, scope)
                    VALUES (SUBSTR(MD5('{$attachment['pimImage_id']}_{$foreignId}'), 16),
                        :nameAsset, :foreignName, :foreignId, :assetId, :sortOrder,'system', :assigned_user_id, :scope)",
                $params
            );
    }
    /**
     * @param string $assetIdsWithChannel
     * @param string $entityName
     */
    protected function setChannelScope(string $assetIdsWithChannel, string $entityName): void
    {
        $field = lcfirst($entityName);
        if (in_array($field, ['product', 'category'])) {
            $this->getEntityManager()
                ->nativeQuery(
                    "UPDATE
                                asset_relation ar
                                    RIGHT JOIN asset a ON a.id = ar.asset_id
                                    RIGHT JOIN pim_image pi ON 
                                        a.file_id = pi.image_id 
                                        AND pi.{$field}_id IS NOT NULL 
                                        AND pi.{$field}_id != ''
                                        AND ar.entity_id = pi.{$field}_id
                            SET ar.scope = 'Channel'
                            WHERE ar.scope = 'Global' 
                                AND pi.scope = 'Channel' 
                                AND ar.asset_id IN ({$assetIdsWithChannel})"
                );
        }
    }
    /**
     * @return array
     */
    protected function getAttachmentsForUp(): array
    {
        return $this
            ->getEntityManager()
            ->nativeQuery(
                'SELECT a.id, a.storage_file_path, a.storage, a.name,a.tmp_path, pi.product_id, pi.category_id,
                             pi.assigned_user_id, pi.id as pimImage_id, pi.scope, pi.sort_order
                        FROM attachment a
                                 RIGHT JOIN pim_image AS pi ON pi.image_id = a.id AND pi.deleted = 0 
                        WHERE a.deleted = 0'
            )
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array
     */
    protected function getPimImageChannels(): array
    {
        return $this
            ->getEntityManager()
            ->nativeQuery('SELECT pim_image_id, channel_id FROM pim_image_channel WHERE deleted = 0')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * @return string
     * @throws Error
     */
    protected function getCollectionAsset(): string
    {
        if (empty($this->collectionId)) {
            $this->collectionId = $this->findCollection();
            if (empty($this->collectionId)) {
                $collection = $this->getEntityManager()->getEntity('Collection');
                $collection->set('isActive', true);
                $collection->set('name', 'PimCollection');
                $collection->set('code', 'pimcollection');
                $this->collectionId = $this->getEntityManager()->saveEntity($collection);
            }
        }

        return $this->collectionId;
    }

    /**
     * @return string|null
     */
    protected function findCollection(): ?string
    {
        /** @var Collection $collection */
        $collection = $this
            ->getEntityManager()
            ->getRepository('Collection')
            ->select(['id'])
            ->where(['code' => 'pimcollection'])
            ->findOne();

        return !empty($collection) ? $collection->get('id') : null;
    }

    /**
     * @param $id
     * @param $attachment
     * @param $pathFile
     */
    protected function updateAttachment($id, $attachment, $pathFile): void
    {
        $dataUpdate = [];
        $dataUpdate['hash_md5'] = hash_file('md5', $pathFile);
        $dataUpdate['related_type'] = 'Asset';
        $dataUpdate['parent_type'] = 'Asset';
        if (empty($attachment['tmp_path'])) {
            $dataUpdate['tmp_path'] = $pathFile;
        }
        $this->updateById('attachment', $dataUpdate, $id);
    }

    /**
     * @param string $fileId
     * @param string $fileName
     * @param string $foreign
     * @param string $foreignId
     * @param string|null $assignedUserId
     *
     * @return string
     * @throws Error
     * @throws Forbidden
     */
    protected function createAsset(string $fileId, string $fileName, string $foreign, string $foreignId, ?string $assignedUserId): string
    {
        $asset = new StdClass();
        $asset->type = 'Gallery Image';
        $asset->privat = true;
        $asset->fileId = $fileId;
        $asset->fileName = $fileName;
        $asset->name = explode('.', $fileName)[0];
        $asset->nameOfFile = $asset->name;
        $asset->code = md5((string)microtime());
        $asset->collectionId = $this->getCollectionAsset();
        $asset->assignedUserId = $assignedUserId;
        $asset->{$foreign . 'Ids'} = [$foreignId];

        foreach ($this->getInputLanguageList() as $lang) {
            $nameField = 'name' . $lang;
            $asset->{$nameField} = $asset->name;
        }

        $assetEntity = $this->getService('Asset')->createEntity($asset);

        return $assetEntity->get('id');
    }

    /**
     * @param string $entityName
     * @param string $assetIdsWithChannel
     */
    protected function insertAssetRelationChannel(string $entityName, string $assetIdsWithChannel): void
    {
        $where = '';
        if ($entityName === 'Product') {
            $where = ' pi.product_id IS NOT NULL AND pi.product_id != \'\'';
        } elseif ($entityName === 'Category') {
            $where = ' pi.category_id IS NOT NULL AND pi.category_id != \'\'';
        } else {
            return;
        }
        $field = lcfirst($entityName);

        $this->getEntityManager()
            ->nativeQuery(
                "
            INSERT INTO asset_relation_channel (channel_id, asset_relation_id)
                SELECT DISTINCT pic.channel_id, ar.id
                FROM pim_image AS pi
                    RIGHT JOIN pim_image_channel AS pic ON pi.id = pic.pim_image_id AND pic.deleted = 0
                    LEFT JOIN asset ON asset.file_id = pi.image_id AND asset.deleted = 0
                    LEFT JOIN asset_relation AS ar
                       ON     ar.entity_name = '{$entityName}' 
                          AND ar.asset_id = asset.id
                          AND ar.entity_id = pi.{$field}_id
                          AND ar.deleted = 0 
                          AND ar.scope = 'Channel'
                WHERE {$where}
                  AND pi.deleted = 0
                  AND pi.scope = 'Channel'
                  AND ar.asset_id IN ({$assetIdsWithChannel});"
            );
    }

    /**
     * @param string $entityName
     */
    protected function updateMainImageUp(string $entityName)
    {
        if ($entityName === 'Product') {
            $where = ' AND pi.product_id IS NOT NULL AND pi.product_id != \'\'';
        } elseif ($entityName === 'Category') {
            $where = ' AND pi.category_id IS NOT NULL AND pi.category_id != \'\'';
        } else {
            return;
        }

        $table = lcfirst($entityName);
        $field = $table;

        $this->getEntityManager()
            ->nativeQuery(
                "
                UPDATE asset_relation ar
                    RIGHT JOIN asset a ON a.id = ar.asset_id
                    RIGHT JOIN pim_image pi ON a.file_id = pi.image_id AND pi.{$field}_id = ar.entity_id {$where}
                SET ar.sort_order = pi.sort_order
                WHERE ar.deleted = 0
                  AND ar.entity_name = '{$entityName}';
                  
                UPDATE {$table} p
                       LEFT JOIN (SELECT ar.entity_id, min(ar.sort_order) as sort
                                   FROM asset_relation ar
                                   WHERE ar.scope = 'Global'
                                     AND ar.entity_name = '{$entityName}'
                                     AND ar.deleted = 0
                                   GROUP BY ar.entity_id
                            ) as sort ON sort.entity_id = p.id
                            LEFT JOIN asset_relation ar ON ar.entity_id = sort.entity_id AND ar.sort_order = sort.sort
                            LEFT JOIN asset a ON ar.asset_id = a.id AND a.type = 'Gallery Image'
                        SET p.image_id = a.file_id, ar.role = '[\"Main\"]'
                        WHERE p.deleted = 0;
                  "
            );
    }

    /**
     * @param string $id
     * @param Exception $e
     */
    protected function setLog(string $id, Exception $e): void
    {
        $GLOBALS['log']->error('Error migration pimImage to Asset. AttachmentId: ' . $id . ';' . $e->getMessage() . ';File:' . $e->getFile() . ';Line:'. $e->getLine());
    }
    /**
     * @param string $table
     * @param array $values
     * @param string $id
     */
    protected function updateById(string $table, array $values, string $id): void
    {
        $setValues = [];
        $params = [];
        foreach ($values as $field => $value) {
            $setValues[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', $setValues) . " WHERE id = '{$id}'";
        $this->getEntityManager()->nativeQuery($sql, $params);
    }

    /**
     * @return bool
     */
    protected function isVariants(): bool
    {
        $result = $this
            ->getEntityManager()
            ->nativeQuery('SHOW COLUMNS FROM `product` LIKE \'configurable_product_id\'')
            ->fetch(PDO::FETCH_ASSOC);

        return !empty($result);
    }

    /**
     * @param $name
     *
     * @return Record
     */
    protected function getService($name): Record
    {
        return $this->getContainer()->get("serviceFactory")->create($name);
    }

    /**
     * @return array
     */
    protected function getInputLanguageList(): array
    {
        $result = [];

        /** @var Config $config */
        $config = $this->container->get('config');

        if ($config->get('isMultilangActive', false)) {
            foreach ($config->get('inputLanguageList', []) as $locale) {
                $result[$locale] = ucfirst(Util::toCamelCase(strtolower($locale)));
            }
        }

        return $result;
    }
}
