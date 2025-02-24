<?php

declare(strict_types=1);

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Acl\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Symfony\Component\Security\Acl\Domain\Acl;
use Symfony\Component\Security\Acl\Domain\Entry;
use Symfony\Component\Security\Acl\Domain\FieldEntry;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Exception\AclNotFoundException;
use Symfony\Component\Security\Acl\Exception\NotAllAclsFoundException;
use Symfony\Component\Security\Acl\Model\AclCacheInterface;
use Symfony\Component\Security\Acl\Model\AclInterface;
use Symfony\Component\Security\Acl\Model\AclProviderInterface;
use Symfony\Component\Security\Acl\Model\EntryInterface;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;
use Symfony\Component\Security\Acl\Model\PermissionGrantingStrategyInterface;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;

/**
 * An ACL provider implementation.
 *
 * This provider assumes that all ACLs share the same PermissionGrantingStrategy.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class AclProvider implements AclProviderInterface
{
    public const MAX_BATCH_SIZE = 30;

    /**
     * @var EntryInterface[]
     */
    protected array $loadedAces = [];

    /**
     * @var array<string,array<string,AclInterface>>
     */
    protected array $loadedAcls = [];

    /**
     * @param array<string,string> $options
     */
    public function __construct(
        protected readonly Connection $connection,
        private readonly PermissionGrantingStrategyInterface $permissionGrantingStrategy,
        protected readonly array $options,
        protected readonly ?AclCacheInterface $cache = null,
    ) {
    }

    /**
     * Retrieves all child object identities from the database.
     *
     * @return ObjectIdentityInterface[] returns an array of child 'ObjectIdentity's
     */
    public function findChildren(ObjectIdentityInterface $parentOid, bool $directChildrenOnly = false): array
    {
        $sql = $this->getFindChildrenSql($parentOid, $directChildrenOnly);

        $children = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $data) {
            $children[] = new ObjectIdentity($data['object_identifier'], $data['class_type']);
        }

        return $children;
    }

    /**
     * Returns the ACL that belongs to the given object identity.
     *
     * @param SecurityIdentityInterface[] $sids
     *
     * @throws AclNotFoundException when there is no ACL
     */
    public function findAcl(ObjectIdentityInterface $oid, array $sids = []): AclInterface
    {
        return $this->findAcls([$oid], $sids)->offsetGet($oid);
    }

    /**
     * Returns the ACLs that belong to the given object identities.
     *
     * @param ObjectIdentityInterface[]   $oids an array of ObjectIdentityInterface implementations
     * @param SecurityIdentityInterface[] $sids an array of SecurityIdentityInterface implementations
     *
     * @return \SplObjectStorage<ObjectIdentityInterface, AclInterface> mapping the passed object identities to ACLs
     *
     * @throws AclNotFoundException when we cannot find an ACL for all identities
     */
    public function findAcls(array $oids, array $sids = []): \SplObjectStorage
    {
        /** @var \SplObjectStorage<ObjectIdentityInterface,AclInterface> */
        $result = new \SplObjectStorage();
        $currentBatch = [];
        $oidLookup = [];

        for ($i = 0, $c = \count($oids); $i < $c; ++$i) {
            $oid = $oids[$i];
            $oidLookupKey = $oid->getIdentifier().$oid->getType();
            $oidLookup[$oidLookupKey] = $oid;
            $aclFound = false;

            // check if result already contains an ACL
            if ($result->contains($oid)) {
                $aclFound = true;
            }

            // check if this ACL has already been hydrated
            if (!$aclFound && isset($this->loadedAcls[$oid->getType()][$oid->getIdentifier()])) {
                $acl = $this->loadedAcls[$oid->getType()][$oid->getIdentifier()];

                if (!$acl->isSidLoaded(...$sids)) {
                    // FIXME: we need to load ACEs for the missing SIDs. This is never
                    //        reached by the default implementation, since we do not
                    //        filter by SID
                    throw new \RuntimeException('This is not supported by the default implementation.');
                } else {
                    $result->attach($oid, $acl);
                    $aclFound = true;
                }
            }

            // check if we can locate the ACL in the cache
            if (!$aclFound && null !== $this->cache) {
                $acl = $this->cache->getFromCacheByIdentity($oid);

                if (null !== $acl) {
                    if ($acl->isSidLoaded(...$sids)) {
                        // check if any of the parents has been loaded since we need to
                        // ensure that there is only ever one ACL per object identity
                        $parentAcl = $acl->getParentAcl();
                        while (null !== $parentAcl) {
                            $parentOid = $parentAcl->getObjectIdentity();

                            if (isset($this->loadedAcls[$parentOid->getType()][$parentOid->getIdentifier()])) {
                                $acl->setParentAcl($this->loadedAcls[$parentOid->getType()][$parentOid->getIdentifier()]);
                                break;
                            } else {
                                $this->loadedAcls[$parentOid->getType()][$parentOid->getIdentifier()] = $parentAcl;
                                $this->updateAceIdentityMap($parentAcl);
                            }

                            $parentAcl = $parentAcl->getParentAcl();
                        }

                        $this->loadedAcls[$oid->getType()][$oid->getIdentifier()] = $acl;
                        $this->updateAceIdentityMap($acl);
                        $result->attach($oid, $acl);
                        $aclFound = true;
                    } else {
                        $this->cache->evictFromCacheByIdentity($oid);

                        foreach ($this->findChildren($oid) as $childOid) {
                            $this->cache->evictFromCacheByIdentity($childOid);
                        }
                    }
                }
            }

            // looks like we have to load the ACL from the database
            if (!$aclFound) {
                $currentBatch[] = $oid;
            }

            // Is it time to load the current batch?
            $currentBatchesCount = \count($currentBatch);
            if ($currentBatchesCount > 0 && (self::MAX_BATCH_SIZE === $currentBatchesCount || ($i + 1) === $c)) {
                try {
                    $loadedBatch = $this->lookupObjectIdentities($currentBatch, $sids, $oidLookup);
                } catch (AclNotFoundException $e) {
                    if ($result->count()) {
                        $partialResultException = new NotAllAclsFoundException('The provider could not find ACLs for all object identities.');
                        $partialResultException->setPartialResult($result);
                        throw $partialResultException;
                    } else {
                        throw $e;
                    }
                }
                foreach ($loadedBatch as $loadedOid) {
                    $loadedAcl = $loadedBatch->offsetGet($loadedOid);

                    if (null !== $this->cache) {
                        $this->cache->putInCache($loadedAcl);
                    }

                    if (isset($oidLookup[$loadedOid->getIdentifier().$loadedOid->getType()])) {
                        $result->attach($loadedOid, $loadedAcl);
                    }
                }

                $currentBatch = [];
            }
        }

        // check that we got ACLs for all the identities
        foreach ($oids as $oid) {
            if (!$result->contains($oid)) {
                if (1 === \count($oids)) {
                    $objectName = method_exists($oid, '__toString') ? $oid : \get_class($oid);
                    throw new AclNotFoundException(sprintf('No ACL found for %s.', $objectName));
                }

                $partialResultException = new NotAllAclsFoundException('The provider could not find ACLs for all object identities.');
                $partialResultException->setPartialResult($result);

                throw $partialResultException;
            }
        }

        return $result;
    }

    /**
     * Constructs the query used for looking up object identities and associated
     * ACEs, and security identities.
     *
     * @param int[] $ancestorIds
     */
    protected function getLookupSql(array $ancestorIds): string
    {
        // FIXME: add support for filtering by sids (right now we select all sids)

        $sql = <<<SELECTCLAUSE
            SELECT
                o.id as acl_id,
                o.object_identifier,
                o.parent_object_identity_id,
                o.entries_inheriting,
                c.class_type,
                e.id as ace_id,
                e.object_identity_id,
                e.field_name,
                e.ace_order,
                e.mask,
                e.granting,
                e.granting_strategy,
                e.audit_success,
                e.audit_failure,
                s.username,
                s.identifier as security_identifier
            FROM
                {$this->options['oid_table_name']} o
            INNER JOIN {$this->options['class_table_name']} c ON c.id = o.class_id
            LEFT JOIN {$this->options['entry_table_name']} e ON (
                e.class_id = o.class_id AND (e.object_identity_id = o.id OR e.object_identity_id IS NULL)
            )
            LEFT JOIN {$this->options['sid_table_name']} s ON (
                s.id = e.security_identity_id
            )

            WHERE (o.id =
SELECTCLAUSE;

        $sql .= implode(' OR o.id = ', $ancestorIds).')';

        return $sql;
    }

    /**
     * @param ObjectIdentityInterface[] $batch
     */
    protected function getAncestorLookupSql(array $batch): string
    {
        $sql = <<<SELECTCLAUSE
            SELECT a.ancestor_id
            FROM
                {$this->options['oid_table_name']} o
            INNER JOIN {$this->options['class_table_name']} c ON c.id = o.class_id
            INNER JOIN {$this->options['oid_ancestors_table_name']} a ON a.object_identity_id = o.id
               WHERE (
SELECTCLAUSE;

        $types = [];
        $count = \count($batch);
        for ($i = 0; $i < $count; ++$i) {
            if (!isset($types[$batch[$i]->getType()])) {
                $types[$batch[$i]->getType()] = true;

                // if there is more than one type we can safely break out of the
                // loop, because it is the differentiator factor on whether to
                // query for only one or more class types
                if (\count($types) > 1) {
                    break;
                }
            }
        }

        if (1 === \count($types)) {
            $ids = [];
            for ($i = 0; $i < $count; ++$i) {
                $identifier = (string) $batch[$i]->getIdentifier();
                $ids[] = $this->connection->quote($identifier);
            }

            $sql .= sprintf(
                '(o.object_identifier IN (%s) AND c.class_type = %s)',
                implode(',', $ids),
                $this->connection->quote($batch[0]->getType())
            );
        } else {
            $where = '(o.object_identifier = %s AND c.class_type = %s)';
            for ($i = 0; $i < $count; ++$i) {
                $sql .= sprintf(
                    $where,
                    $this->connection->quote($batch[$i]->getIdentifier()),
                    $this->connection->quote($batch[$i]->getType())
                );

                if ($i + 1 < $count) {
                    $sql .= ' OR ';
                }
            }
        }

        $sql .= ')';

        return $sql;
    }

    /**
     * Constructs the SQL for retrieving child object identities for the given
     * object identities.
     */
    protected function getFindChildrenSql(ObjectIdentityInterface $oid, bool $directChildrenOnly): string
    {
        if (false === $directChildrenOnly) {
            $query = <<<FINDCHILDREN
                SELECT o.object_identifier, c.class_type
                FROM
                    {$this->options['oid_table_name']} o
                INNER JOIN {$this->options['class_table_name']} c ON c.id = o.class_id
                INNER JOIN {$this->options['oid_ancestors_table_name']} a ON a.object_identity_id = o.id
                WHERE
                    a.ancestor_id = %d AND a.object_identity_id != a.ancestor_id
FINDCHILDREN;
        } else {
            $query = <<<FINDCHILDREN
                SELECT o.object_identifier, c.class_type
                FROM {$this->options['oid_table_name']} o
                INNER JOIN {$this->options['class_table_name']} c ON c.id = o.class_id
                WHERE o.parent_object_identity_id = %d
FINDCHILDREN;
        }

        return sprintf($query, $this->retrieveObjectIdentityPrimaryKey($oid));
    }

    /**
     * Constructs the SQL for retrieving the primary key of the given object
     * identity.
     */
    protected function getSelectObjectIdentityIdSql(ObjectIdentityInterface $oid): string
    {
        $query = <<<QUERY
            SELECT o.id
            FROM %s o
            INNER JOIN %s c ON c.id = o.class_id
            WHERE o.object_identifier = %s AND c.class_type = %s
QUERY;

        return sprintf(
            $query,
            $this->options['oid_table_name'],
            $this->options['class_table_name'],
            $this->connection->quote((string) $oid->getIdentifier()),
            $this->connection->quote((string) $oid->getType())
        );
    }

    /**
     * Returns the primary key of the passed object identity.
     */
    final protected function retrieveObjectIdentityPrimaryKey(ObjectIdentityInterface $oid): int|false
    {
        return $this->connection->executeQuery($this->getSelectObjectIdentityIdSql($oid))->fetchOne();
    }

    /**
     * This method is called when an ACL instance is retrieved from the cache.
     */
    private function updateAceIdentityMap(AclInterface $acl): void
    {
        foreach (['classAces', 'classFieldAces', 'objectAces', 'objectFieldAces'] as $property) {
            $reflection = new \ReflectionProperty($acl, $property);
            $reflection->setAccessible(true);
            $value = $reflection->getValue($acl);

            if ('classAces' === $property || 'objectAces' === $property) {
                $this->doUpdateAceIdentityMap($value);
            } else {
                foreach ($value as $field => $aces) {
                    $this->doUpdateAceIdentityMap($value[$field]);
                }
            }

            $reflection->setValue($acl, $value);
            $reflection->setAccessible(false);
        }
    }

    /**
     * Retrieves all the ids which need to be queried from the database
     * including the ids of parent ACLs.
     *
     * @param ObjectIdentityInterface[] $batch
     *
     * @return int[]
     */
    private function getAncestorIds(array $batch): array
    {
        $sql = $this->getAncestorLookupSql($batch);

        $ancestorIds = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $data) {
            // FIXME: skip ancestors which are cached
            // Fix: Oracle returns keys in uppercase
            $ancestorIds[] = reset($data);
        }

        return $ancestorIds;
    }

    /**
     * Does either overwrite the passed ACE, or saves it in the global identity
     * map to ensure every ACE only gets instantiated once.
     *
     * @param EntryInterface[] $aces
     */
    private function doUpdateAceIdentityMap(array &$aces): void
    {
        foreach ($aces as $index => $ace) {
            if (isset($this->loadedAces[$ace->getId()])) {
                $aces[$index] = $this->loadedAces[$ace->getId()];
            } else {
                $this->loadedAces[$ace->getId()] = $ace;
            }
        }
    }

    /**
     * This method is called for object identities which could not be retrieved
     * from the cache, and for which thus a database query is required.
     *
     * @param ObjectIdentityInterface[]             $batch
     * @param array<int,SecurityIdentityInterface>  $sids
     * @param array<string,ObjectIdentityInterface> $oidLookup
     *
     * @return \SplObjectStorage<ObjectIdentityInterface,AclInterface> mapping object identities to ACL instances
     *
     * @throws AclNotFoundException
     */
    private function lookupObjectIdentities(array $batch, array $sids, array $oidLookup): \SplObjectStorage
    {
        $ancestorIds = $this->getAncestorIds($batch);
        if (!$ancestorIds) {
            throw new AclNotFoundException('There is no ACL for the given object identity.');
        }

        $sql = $this->getLookupSql($ancestorIds);
        $stmt = $this->connection->executeQuery($sql);

        return $this->hydrateObjectIdentities($stmt, $oidLookup, $sids);
    }

    /**
     * This method is called to hydrate ACLs and ACEs.
     *
     * This method was designed for performance; thus, a lot of code has been
     * inlined at the cost of readability, and maintainability.
     *
     * Keep in mind that changes to this method might severely reduce the
     * performance of the entire ACL system.
     *
     * @param array<string,ObjectIdentityInterface> $oidLookup
     * @param array<int,SecurityIdentityInterface>  $sids
     *
     * @return \SplObjectStorage<ObjectIdentityInterface,AclInterface>
     *
     * @throws \RuntimeException
     */
    private function hydrateObjectIdentities(Result $stmt, array $oidLookup, array $sids): \SplObjectStorage
    {
        /** @var \SplObjectStorage<Acl,string> */
        $parentIdToFill = new \SplObjectStorage();
        $acls = $aces = $emptyArray = [];
        $oidCache = $oidLookup;
        /** @var \SplObjectStorage<ObjectIdentityInterface,AclInterface> */
        $result = new \SplObjectStorage();
        $loadedAces = &$this->loadedAces;
        $loadedAcls = &$this->loadedAcls;
        $permissionGrantingStrategy = $this->permissionGrantingStrategy;

        // we need these to set protected properties on hydrated objects
        $aclReflection = new \ReflectionClass(Acl::class);
        $aclClassAcesProperty = $aclReflection->getProperty('classAces');
        $aclClassAcesProperty->setAccessible(true);
        $aclClassFieldAcesProperty = $aclReflection->getProperty('classFieldAces');
        $aclClassFieldAcesProperty->setAccessible(true);
        $aclObjectAcesProperty = $aclReflection->getProperty('objectAces');
        $aclObjectAcesProperty->setAccessible(true);
        $aclObjectFieldAcesProperty = $aclReflection->getProperty('objectFieldAces');
        $aclObjectFieldAcesProperty->setAccessible(true);
        $aclParentAclProperty = $aclReflection->getProperty('parentAcl');
        $aclParentAclProperty->setAccessible(true);

        // fetchAll() consumes more memory than consecutive calls to fetch(),
        // but it is faster
        foreach ($stmt->fetchAllNumeric() as $data) {
            [$aclId,
                $objectIdentifier,
                $parentObjectIdentityId,
                $entriesInheriting,
                $classType,
                $aceId,
                $objectIdentityId,
                $fieldName,
                $aceOrder,
                $mask,
                $granting,
                $grantingStrategy,
                $auditSuccess,
                $auditFailure,
                $username,
                $securityIdentifier] = array_values($data);

            // FIX: remove duplicate slashes
            $classType = str_replace('\\\\', '\\', $classType);

            // has the ACL been hydrated during this hydration cycle?
            if (isset($acls[$aclId])) {
                $acl = $acls[$aclId];
            // has the ACL been hydrated during any previous cycle, or was possibly loaded
            // from cache?
            } elseif (isset($loadedAcls[$classType][$objectIdentifier])) {
                $acl = $loadedAcls[$classType][$objectIdentifier];

                // keep reference in local array (saves us some hash calculations)
                $acls[$aclId] = $acl;

                // attach ACL to the result set; even though we do not enforce that every
                // object identity has only one instance, we must make sure to maintain
                // referential equality with the oids passed to findAcls()
                $oidCacheKey = $objectIdentifier.$classType;
                if (!isset($oidCache[$oidCacheKey])) {
                    $oidCache[$oidCacheKey] = $acl->getObjectIdentity();
                }
                $result->attach($oidCache[$oidCacheKey], $acl);
            // so, this hasn't been hydrated yet
            } else {
                // create object identity if we haven't done so yet
                $oidLookupKey = $objectIdentifier.$classType;
                if (!isset($oidCache[$oidLookupKey])) {
                    $oidCache[$oidLookupKey] = new ObjectIdentity($objectIdentifier, $classType);
                }

                $acl = new Acl((int) $aclId, $oidCache[$oidLookupKey], $permissionGrantingStrategy, $emptyArray, (bool) $entriesInheriting);

                // keep a local, and global reference to this ACL
                $loadedAcls[$classType][$objectIdentifier] = $acl;
                $acls[$aclId] = $acl;

                // try to fill in parent ACL, or defer until all ACLs have been hydrated
                if (null !== $parentObjectIdentityId) {
                    if (isset($acls[$parentObjectIdentityId])) {
                        $aclParentAclProperty->setValue($acl, $acls[$parentObjectIdentityId]);
                    } else {
                        $parentIdToFill->attach($acl, $parentObjectIdentityId);
                    }
                }

                $result->attach($oidCache[$oidLookupKey], $acl);
            }

            // check if this row contains an ACE record
            if (null !== $aceId) {
                // have we already hydrated ACEs for this ACL?
                if (!isset($aces[$aclId])) {
                    $aces[$aclId] = [$emptyArray, $emptyArray, $emptyArray, $emptyArray];
                }

                // has this ACE already been hydrated during a previous cycle, or
                // possible been loaded from cache?
                // It is important to only ever have one ACE instance per actual row since
                // some ACEs are shared between ACL instances
                if (!isset($loadedAces[$aceId])) {
                    if (!isset($sids[$key = ($username ? '1' : '0').$securityIdentifier])) {
                        if ($username) {
                            $sids[$key] = new UserSecurityIdentity(
                                substr($securityIdentifier, 1 + $pos = strpos($securityIdentifier, '-')),
                                substr($securityIdentifier, 0, $pos)
                            );
                        } else {
                            $sids[$key] = new RoleSecurityIdentity($securityIdentifier);
                        }
                    }

                    if (null === $fieldName) {
                        $loadedAces[$aceId] = new Entry((int) $aceId, $acl, $sids[$key], $grantingStrategy, (int) $mask, (bool) $granting, (bool) $auditFailure, (bool) $auditSuccess);
                    } else {
                        $loadedAces[$aceId] = new FieldEntry((int) $aceId, $acl, $fieldName, $sids[$key], $grantingStrategy, (int) $mask, (bool) $granting, (bool) $auditFailure, (bool) $auditSuccess);
                    }
                }
                $ace = $loadedAces[$aceId];

                // assign ACE to the correct property
                if (null === $objectIdentityId) {
                    if (null === $fieldName) {
                        $aces[$aclId][0][$aceOrder] = $ace;
                    } else {
                        $aces[$aclId][1][$fieldName][$aceOrder] = $ace;
                    }
                } else {
                    if (null === $fieldName) {
                        $aces[$aclId][2][$aceOrder] = $ace;
                    } else {
                        $aces[$aclId][3][$fieldName][$aceOrder] = $ace;
                    }
                }
            }
        }

        // We do not sort on database level since we only want certain subsets to be sorted,
        // and we are going to read the entire result set anyway.
        // Sorting on DB level increases query time by an order of magnitude while it is
        // almost negligible when we use PHPs array sort functions.
        foreach ($aces as $aclId => $aceData) {
            $acl = $acls[$aclId];

            ksort($aceData[0]);
            $aclClassAcesProperty->setValue($acl, $aceData[0]);

            foreach (array_keys($aceData[1]) as $fieldName) {
                ksort($aceData[1][$fieldName]);
            }
            $aclClassFieldAcesProperty->setValue($acl, $aceData[1]);

            ksort($aceData[2]);
            $aclObjectAcesProperty->setValue($acl, $aceData[2]);

            foreach (array_keys($aceData[3]) as $fieldName) {
                ksort($aceData[3][$fieldName]);
            }
            $aclObjectFieldAcesProperty->setValue($acl, $aceData[3]);
        }

        // fill-in parent ACLs where this hasn't been done yet cause the parent ACL was not
        // yet available
        $processed = 0;
        foreach ($parentIdToFill as $acl) {
            $parentId = $parentIdToFill->offsetGet($acl);

            // let's see if we have already hydrated this
            if (isset($acls[$parentId])) {
                $aclParentAclProperty->setValue($acl, $acls[$parentId]);
                ++$processed;

                continue;
            }
        }

        // reset reflection changes
        $aclClassAcesProperty->setAccessible(false);
        $aclClassFieldAcesProperty->setAccessible(false);
        $aclObjectAcesProperty->setAccessible(false);
        $aclObjectFieldAcesProperty->setAccessible(false);
        $aclParentAclProperty->setAccessible(false);

        // this should never be true if the database integrity hasn't been compromised
        if ($processed < \count($parentIdToFill)) {
            throw new \RuntimeException('Not all parent ids were populated. This implies an integrity problem.');
        }

        return $result;
    }
}
