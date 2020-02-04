<?php
declare(strict_types=1);


namespace DamCommon\Utils;

use Dam\Entities\Asset;
use Dam\Repositories\Attachment;
use Espo\Core\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\Repository;
use Dam\Core\Validation\Items\Unique;

/**
 * Class AssetRelation
 * @package DamCommon\Utils
 */
class AssetRelation
{
    use \Treo\Traits\ContainerTrait;

    /**
     * @param \Dam\Entities\AssetRelation $assetRelation
     * @return bool
     */
    public static function isMainRole(\Dam\Entities\AssetRelation $assetRelation): bool
    {
        return in_array('Main', (array)$assetRelation->get('role'), true);
    }

    /**
     * @param array $selectParams
     * @param string $productId
     * @param string $where
     * @return \PDOStatement
     */
    public function getAssetsRelationsByProduct(array $selectParams, string $productId, string $where = ''): \PDOStatement
    {
        $select = '';
        foreach ($selectParams as $alias => $selectParam) {
            $a = is_string($alias) ? $alias : $selectParam;
            $select .=  "$selectParam AS $a,";
        }
        $select = mb_substr($select, 0, -1);

        $sql = "
            SELECT {$select}
            FROM asset_relation as assetRelation
            LEFT JOIN asset ON assetRelation.asset_id = asset.id
            LEFT JOIN asset_relation_channel assetRelationChannel 
                ON assetRelationChannel.asset_relation_id = assetRelation.id AND assetRelationChannel.deleted = 0
            LEFT JOIN channel ON channel.id = assetRelationChannel.channel_id AND channel.deleted = 0
            WHERE assetRelation.entity_id = :productId
                AND assetRelation.entity_name = 'Product'
                AND assetRelation.deleted = '0'    
                {$where}
            ORDER BY assetRelation.sort_order ASC, assetRelation.modified_at DESC;";

        return $this
            ->getEntityManager()
            ->nativeQuery($sql, ['productId' => $productId]);
    }

    /**
     * @param string $link
     * @return Asset|null
     */
    public function getAsset(string $link): ?Asset
    {
        $hash = hash_file('md5', $link);
        $idAttachment =  $this
            ->getEntityManager()
            ->getRepository('Attachment')
            ->select(['id'])
            ->where([
                'hash_md5'    => $hash,
                'deleted'     => 0,
                'relatedId!=' => null
            ])
            ->findOne();

        return $this
            ->getEntityManager()
            ->getRepository('Asset')
            ->select(['id', 'type', 'fileId'])
            ->where([
                'fileId' => $idAttachment
            ])
            ->findOne();
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager(): EntityManager
    {
      return $this->getContainer()->get('entityManager');
    }
}