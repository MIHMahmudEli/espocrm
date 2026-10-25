<?php

namespace Espo\Custom\Tools\Activities;

use Espo\Core\Acl;
use Espo\Core\Select\Helpers\RelationQueryHelper;
use Espo\Core\ServiceFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Crm\Entities\Call;
use Espo\Modules\Crm\Tools\Activities\Service as BaseService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;

/**
 * Activities/history service with an optional product-service filter.
 *
 * The client sends `productServiceIds` (a CSV of ids of the record the panel is
 * shown on). When present, only activity rows that are tied to one of those
 * product services are returned. Activity types that cannot be tied to a product
 * service (Meeting, Email, …) are dropped while the filter is active.
 */
class Service extends BaseService
{
    public const PRODUCT_SERVICE_ENTITY_TYPE = 'CProductService';
    public const PRODUCT_SERVICE_CALL_LINK = 'calls';

    private EntityManager $em;

    /** @var string[]|null */
    private ?array $productServiceIdList = null;

    /** @var string[]|null */
    private ?array $callIdList = null;

    public function __construct(
        ListLoadProcessor $listLoadProcessor,
        RecordServiceContainer $recordServiceContainer,
        SelectBuilderFactory $selectBuilderFactory,
        Config $config,
        Metadata $metadata,
        Acl $acl,
        ServiceFactory $serviceFactory,
        EntityManager $entityManager,
        User $user,
        RelationQueryHelper $relationQueryHelper,
    ) {
        parent::__construct(
            $listLoadProcessor,
            $recordServiceContainer,
            $selectBuilderFactory,
            $config,
            $metadata,
            $acl,
            $serviceFactory,
            $entityManager,
            $user,
            $relationQueryHelper,
        );

        $this->em = $entityManager;
    }

    /**
     * Set the filter. Pass `null` for "no filter".
     *
     * @param ?string[] $idList
     */
    public function setProductServiceIdList(?array $idList): void
    {
        $this->productServiceIdList = $idList === null ?
            null :
            array_values(array_unique($idList));

        $this->callIdList = null;
    }

    /**
     * Set the filter from a raw query-param value (CSV string, array or absent).
     */
    public function setProductServiceIdListFromRaw(mixed $raw): void
    {
        if ($raw === null) {
            $this->setProductServiceIdList(null);

            return;
        }

        $itemList = is_array($raw) ? $raw : explode(',', (string) $raw);

        $idList = [];

        foreach ($itemList as $item) {
            $item = trim((string) $item);

            if ($item === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $item)) {
                continue;
            }

            $idList[] = $item;
        }

        $this->setProductServiceIdList($idList);
    }

    public function clearProductServiceIdList(): void
    {
        $this->setProductServiceIdList(null);
    }

    protected function getActivitiesQuery(Entity $entity, string $scope, array $statusList = [])
    {
        $query = parent::getActivitiesQuery($entity, $scope, $statusList);

        if ($this->productServiceIdList === null) {
            return $query;
        }

        $callIdList = $scope === Call::ENTITY_TYPE ? $this->getCallIdList() : [];

        return $this->applyCallIdList($query, $callIdList);
    }

    /**
     * @param Select|Select[] $query
     * @param string[] $callIdList
     * @return Select|Select[]
     *
     * @noinspection PhpUnusedParameterInspection
     */
    private function applyCallIdList($query, array $callIdList)
    {
        $queryList = is_array($query) ? $query : [$query];
        $resultList = [];

        foreach ($queryList as $itemQuery) {
            $resultList[] = $this->em
                ->getQueryBuilder()
                ->select()
                ->clone($itemQuery)
                ->where(['id' => $callIdList])
                ->build();
        }

        return is_array($query) ? $resultList : $resultList[0];
    }

    /**
     * @return string[]
     */
    private function getCallIdList(): array
    {
        if ($this->callIdList !== null) {
            return $this->callIdList;
        }

        $callIdList = [];

        foreach ($this->productServiceIdList ?? [] as $productServiceId) {
            $productService = $this->em->getEntityById(
                self::PRODUCT_SERVICE_ENTITY_TYPE,
                $productServiceId
            );

            if (!$productService || !$productService->hasRelation(self::PRODUCT_SERVICE_CALL_LINK)) {
                continue;
            }

            $callCollection = $this->em
                ->getRelation($productService, self::PRODUCT_SERVICE_CALL_LINK)
                ->select(['id'])
                ->find();

            foreach ($callCollection as $call) {
                $callIdList[] = $call->getId();
            }
        }

        $this->callIdList = array_values(array_unique($callIdList));

        return $this->callIdList;
    }
}
