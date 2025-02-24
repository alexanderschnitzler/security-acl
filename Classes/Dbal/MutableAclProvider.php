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
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Statement;
use Doctrine\Persistence\PropertyChangedListener;
use Symfony\Component\Security\Acl\Domain\Acl;
use Symfony\Component\Security\Acl\Domain\Entry;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Exception\AclAlreadyExistsException;
use Symfony\Component\Security\Acl\Exception\ConcurrentModificationException;
use Symfony\Component\Security\Acl\Model\AclCacheInterface;
use Symfony\Component\Security\Acl\Model\AclInterface;
use Symfony\Component\Security\Acl\Model\EntryInterface;
use Symfony\Component\Security\Acl\Model\MutableAclInterface;
use Symfony\Component\Security\Acl\Model\MutableAclProviderInterface;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;
use Symfony\Component\Security\Acl\Model\PermissionGrantingStrategyInterface;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;

/**
 * An implementation of the MutableAclProviderInterface using Doctrine DBAL.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class MutableAclProvider extends AclProvider implements MutableAclProviderInterface, PropertyChangedListener
{
    /**
     * @param array<string,string> $options
     */
    public function __construct(
        Connection $connection,
        PermissionGrantingStrategyInterface $permissionGrantingStrategy,
        array $options,
        ?AclCacheInterface $cache = null,
        private readonly \SplObjectStorage $propertyChanges = new \SplObjectStorage(),
    ) {
        parent::__construct($connection, $permissionGrantingStrategy, $options, $cache);
    }

    public function createAcl(ObjectIdentityInterface $oid): MutableAclInterface
    {
        if (false !== $this->retrieveObjectIdentityPrimaryKey($oid)) {
            $objectName = method_exists($oid, '__toString') ? $oid : $oid::class;
            throw new AclAlreadyExistsException(sprintf('%s is already associated with an ACL.', $objectName));
        }

        $this->connection->beginTransaction();
        try {
            $this->createObjectIdentity($oid);

            $pk = $this->retrieveObjectIdentityPrimaryKey($oid);

            $statement = $this->getInsertObjectIdentityRelationSql();
            $statement->bindValue(':object_identity_id', $pk, ParameterType::INTEGER);
            $statement->bindValue(':ancestor_id', $pk, ParameterType::INTEGER);
            $statement->executeStatement();

            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();

            throw $e;
        }

        // re-read the ACL from the database to ensure proper caching, etc.
        return $this->findAcl($oid);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAcl(ObjectIdentityInterface $oid): void
    {
        $this->connection->beginTransaction();
        try {
            foreach ($this->findChildren($oid, true) as $childOid) {
                $this->deleteAcl($childOid);
            }

            $oidPK = $this->retrieveObjectIdentityPrimaryKey($oid);

            $this->deleteAccessControlEntries($oidPK);
            $this->deleteObjectIdentityRelations($oidPK);
            $this->deleteObjectIdentity($oidPK);

            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();

            throw $e;
        }

        // evict the ACL from the in-memory identity map
        if (isset($this->loadedAcls[$oid->getType()][$oid->getIdentifier()])) {
            $this->propertyChanges->offsetUnset($this->loadedAcls[$oid->getType()][$oid->getIdentifier()]);
            unset($this->loadedAcls[$oid->getType()][$oid->getIdentifier()]);
        }

        // evict the ACL from any caches
        if (null !== $this->cache) {
            $this->cache->evictFromCacheByIdentity($oid);
        }
    }

    /**
     * Deletes the security identity from the database.
     * ACL entries have the CASCADE option on their foreign key so they will also get deleted.
     *
     * @throws \InvalidArgumentException
     */
    public function deleteSecurityIdentity(SecurityIdentityInterface $sid): void
    {
        if ($sid instanceof UserSecurityIdentity) {
            $identifier = $sid->getClass().'-'.$sid->getUsername();
            $username = true;
        } elseif ($sid instanceof RoleSecurityIdentity) {
            $identifier = $sid->getRole();
            $username = false;
        } else {
            throw new \InvalidArgumentException('$sid must either be an instance of UserSecurityIdentity, or RoleSecurityIdentity.');
        }

        $deleteSecurityIdentityIdStatement = $this->getDeleteSecurityIdentityIdSql();
        $deleteSecurityIdentityIdStatement->bindValue(':identifier', $identifier);
        $deleteSecurityIdentityIdStatement->bindValue(':username', $username, ParameterType::BOOLEAN);
        $deleteSecurityIdentityIdStatement->executeStatement();
    }

    /**
     * {@inheritdoc}
     */
    public function findAcls(array $oids, array $sids = []): \SplObjectStorage
    {
        $result = parent::findAcls($oids, $sids);

        foreach ($result as $oid) {
            $acl = $result->offsetGet($oid);

            if (false === $this->propertyChanges->contains($acl) && $acl instanceof MutableAclInterface) {
                $acl->addPropertyChangedListener($this);
                $this->propertyChanges->attach($acl, []);
            }

            $parentAcl = $acl->getParentAcl();
            while (null !== $parentAcl) {
                if (false === $this->propertyChanges->contains($parentAcl) && $acl instanceof MutableAclInterface) {
                    $parentAcl->addPropertyChangedListener($this);
                    $this->propertyChanges->attach($parentAcl, []);
                }

                $parentAcl = $parentAcl->getParentAcl();
            }
        }

        return $result;
    }

    /**
     * Implementation of PropertyChangedListener.
     *
     * This allows us to keep track of which values have been changed, so we don't
     * have to do a full introspection when ->updateAcl() is called.
     *
     * @throws \InvalidArgumentException
     */
    public function propertyChanged(object $sender, string $propertyName, mixed $oldValue, mixed $newValue): void
    {
        if (!$sender instanceof MutableAclInterface && !$sender instanceof EntryInterface) {
            throw new \InvalidArgumentException('$sender must be an instance of MutableAclInterface, or EntryInterface.');
        }

        if ($sender instanceof EntryInterface) {
            if (null === $sender->getId()) {
                return;
            }

            $ace = $sender;
            $sender = $ace->getAcl();
        } else {
            $ace = null;
        }

        if (false === $this->propertyChanges->contains($sender)) {
            throw new \InvalidArgumentException('$sender is not being tracked by this provider.');
        }

        $propertyChanges = $this->propertyChanges->offsetGet($sender);
        if (null === $ace) {
            if (isset($propertyChanges[$propertyName])) {
                $oldValue = $propertyChanges[$propertyName][0];
                if ($oldValue === $newValue) {
                    unset($propertyChanges[$propertyName]);
                } else {
                    $propertyChanges[$propertyName] = [$oldValue, $newValue];
                }
            } else {
                $propertyChanges[$propertyName] = [$oldValue, $newValue];
            }
        } else {
            if (!isset($propertyChanges['aces'])) {
                $propertyChanges['aces'] = new \SplObjectStorage();
            }

            $acePropertyChanges = $propertyChanges['aces']->contains($ace) ? $propertyChanges['aces']->offsetGet($ace) : [];

            if (isset($acePropertyChanges[$propertyName])) {
                $oldValue = $acePropertyChanges[$propertyName][0];
                if ($oldValue === $newValue) {
                    unset($acePropertyChanges[$propertyName]);
                } else {
                    $acePropertyChanges[$propertyName] = [$oldValue, $newValue];
                }
            } else {
                $acePropertyChanges[$propertyName] = [$oldValue, $newValue];
            }

            if (\count($acePropertyChanges) > 0) {
                $propertyChanges['aces']->offsetSet($ace, $acePropertyChanges);
            } else {
                $propertyChanges['aces']->offsetUnset($ace);

                if (0 === \count($propertyChanges['aces'])) {
                    unset($propertyChanges['aces']);
                }
            }
        }

        $this->propertyChanges->offsetSet($sender, $propertyChanges);
    }

    /**
     * {@inheritdoc}
     */
    public function updateAcl(MutableAclInterface $acl): void
    {
        if (!$this->propertyChanges->contains($acl)) {
            throw new \InvalidArgumentException('$acl is not tracked by this provider.');
        }

        $propertyChanges = $this->propertyChanges->offsetGet($acl);
        // check if any changes were made to this ACL
        if (0 === \count($propertyChanges)) {
            return;
        }

        $sets = $sharedPropertyChanges = [];

        $this->connection->beginTransaction();
        try {
            if (isset($propertyChanges['entriesInheriting'])) {
                $sets[] = [
                    'key' => 'entries_inheriting',
                    'value' => $propertyChanges['entriesInheriting'][1],
                    'type' => ParameterType::BOOLEAN,
                ];
            }

            if (isset($propertyChanges['parentAcl'])) {
                if (null === $propertyChanges['parentAcl'][1]) {
                    $sets[] = [
                        'key' => 'parent_object_identity_id',
                        'value' => null,
                        'type' => ParameterType::NULL,
                    ];
                } else {
                    $sets[] = [
                        'key' => 'parent_object_identity_id',
                        'value' => (int) $propertyChanges['parentAcl'][1]->getId(),
                        'type' => ParameterType::INTEGER,
                    ];
                }

                $this->regenerateAncestorRelations($acl);
                $childAcls = $this->findAcls($this->findChildren($acl->getObjectIdentity(), false));
                foreach ($childAcls as $childOid) {
                    $this->regenerateAncestorRelations($childAcls[$childOid]);
                }
            }

            // check properties for deleted, and created ACEs, and perform deletions
            // we need to perform deletions before updating existing ACEs, in order to
            // preserve uniqueness of the order field
            if (isset($propertyChanges['classAces'])) {
                $this->updateOldAceProperty('classAces', $propertyChanges['classAces']);
            }
            if (isset($propertyChanges['classFieldAces'])) {
                $this->updateOldFieldAceProperty('classFieldAces', $propertyChanges['classFieldAces']);
            }
            if (isset($propertyChanges['objectAces'])) {
                $this->updateOldAceProperty('objectAces', $propertyChanges['objectAces']);
            }
            if (isset($propertyChanges['objectFieldAces'])) {
                $this->updateOldFieldAceProperty('objectFieldAces', $propertyChanges['objectFieldAces']);
            }

            // this includes only updates of existing ACEs, but neither the creation, nor
            // the deletion of ACEs; these are tracked by changes to the ACL's respective
            // properties (classAces, classFieldAces, objectAces, objectFieldAces)
            if (isset($propertyChanges['aces'])) {
                $this->updateAces($propertyChanges['aces']);
            }

            // check properties for deleted, and created ACEs, and perform creations
            if (isset($propertyChanges['classAces'])) {
                $this->updateNewAceProperty('classAces', $propertyChanges['classAces']);
                $sharedPropertyChanges['classAces'] = $propertyChanges['classAces'];
            }
            if (isset($propertyChanges['classFieldAces'])) {
                $this->updateNewFieldAceProperty('classFieldAces', $propertyChanges['classFieldAces']);
                $sharedPropertyChanges['classFieldAces'] = $propertyChanges['classFieldAces'];
            }
            if (isset($propertyChanges['objectAces'])) {
                $this->updateNewAceProperty('objectAces', $propertyChanges['objectAces']);
            }
            if (isset($propertyChanges['objectFieldAces'])) {
                $this->updateNewFieldAceProperty('objectFieldAces', $propertyChanges['objectFieldAces']);
            }

            // if there have been changes to shared properties, we need to synchronize other
            // ACL instances for object identities of the same type that are already in-memory
            if (\count($sharedPropertyChanges) > 0) {
                $classAcesProperty = new \ReflectionProperty(Acl::class, 'classAces');
                $classAcesProperty->setAccessible(true);
                $classFieldAcesProperty = new \ReflectionProperty(Acl::class, 'classFieldAces');
                $classFieldAcesProperty->setAccessible(true);

                foreach ($this->loadedAcls[$acl->getObjectIdentity()->getType()] as $sameTypeAcl) {
                    if (isset($sharedPropertyChanges['classAces'])) {
                        if ($acl !== $sameTypeAcl && $classAcesProperty->getValue($sameTypeAcl) !== $sharedPropertyChanges['classAces'][0]) {
                            throw new ConcurrentModificationException('The "classAces" property has been modified concurrently.');
                        }

                        $classAcesProperty->setValue($sameTypeAcl, $sharedPropertyChanges['classAces'][1]);
                    }

                    if (isset($sharedPropertyChanges['classFieldAces'])) {
                        if ($acl !== $sameTypeAcl && $classFieldAcesProperty->getValue($sameTypeAcl) !== $sharedPropertyChanges['classFieldAces'][0]) {
                            throw new ConcurrentModificationException('The "classFieldAces" property has been modified concurrently.');
                        }

                        $classFieldAcesProperty->setValue($sameTypeAcl, $sharedPropertyChanges['classFieldAces'][1]);
                    }
                }
            }

            // persist any changes to the acl_object_identities table
            if (\count($sets) > 0) {
                $statement = $this->getUpdateObjectIdentitySql(array_column($sets, 'key'));
                $statement->bindValue('id', $acl->getId());

                foreach ($sets as $set) {
                    $statement->bindValue(':'.$set['key'], $set['value'], $set['type']);
                }

                $statement->executeStatement();
            }

            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();

            throw $e;
        }

        $this->propertyChanges->offsetSet($acl, []);

        if (null !== $this->cache) {
            if (\count($sharedPropertyChanges) > 0) {
                // FIXME: Currently, there is no easy way to clear the cache for ACLs
                //        of a certain type. The problem here is that we need to make
                //        sure to clear the cache of all child ACLs as well, and these
                //        child ACLs might be of a different class type.
                $this->cache->clearCache();
            } else {
                // if there are no shared property changes, it's sufficient to just delete
                // the cache for this ACL
                $this->cache->evictFromCacheByIdentity($acl->getObjectIdentity());

                foreach ($this->findChildren($acl->getObjectIdentity()) as $childOid) {
                    $this->cache->evictFromCacheByIdentity($childOid);
                }
            }
        }
    }

    /**
     * Updates a user security identity when the user's username changes.
     */
    public function updateUserSecurityIdentity(UserSecurityIdentity $usid, string $oldUsername): void
    {
        if ($usid->getUsername() == $oldUsername) {
            throw new \InvalidArgumentException('There are no changes.');
        }

        $oldIdentifier = $usid->getClass().'-'.$oldUsername;
        $newIdentifier = $usid->getClass().'-'.$usid->getUsername();

        $statement = $this->getUpdateUserSecurityIdentitySql();
        $statement->bindValue(':identifier', $newIdentifier);
        $statement->bindValue(':existing_identifier', $oldIdentifier);
        $statement->bindValue(':username', true, ParameterType::BOOLEAN);
        $statement->executeStatement();
    }

    /**
     * Constructs the SQL for deleting access control entries.
     */
    protected function getDeleteAccessControlEntriesSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->delete($this->options['entry_table_name'])
            ->where($queryBuilder->expr()->eq('object_identity_id', ':object_identity_id'))
        ;

        return $this->connection->prepare($queryBuilder->getSQL());
    }

    /**
     * Constructs the SQL for deleting a specific ACE.
     */
    protected function getDeleteAccessControlEntrySql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->delete($this->options['entry_table_name'])
            ->where($queryBuilder->expr()->eq('id', ':id'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for deleting an object identity.
     */
    protected function getDeleteObjectIdentitySql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->delete($this->options['oid_table_name'])
            ->where($queryBuilder->expr()->eq('id', ':id'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for deleting relation entries.
     */
    protected function getDeleteObjectIdentityRelationsSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->delete($this->options['oid_ancestors_table_name'])
            ->where($queryBuilder->expr()->eq('object_identity_id', ':object_identity_id'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for inserting an ACE.
     */
    protected function getInsertAccessControlEntrySql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->insert($this->options['entry_table_name'])
            ->values([
                'class_id' => ':class_id',
                'object_identity_id' => ':object_identity_id',
                'field_name' => ':field_name',
                'ace_order' => ':ace_order',
                'security_identity_id' => ':security_identity_id',
                'mask' => ':mask',
                'granting' => ':granting',
                'granting_strategy' => ':granting_strategy',
                'audit_success' => ':audit_success',
                'audit_failure' => ':audit_failure',
            ]);

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for inserting a new class type.
     */
    protected function getInsertClassSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->insert($this->options['class_table_name'])
            ->values(['class_type' => ':class_type']);

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for inserting a relation entry.
     */
    protected function getInsertObjectIdentityRelationSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->insert($this->options['oid_ancestors_table_name'])
            ->values([
                'object_identity_id' => ':object_identity_id',
                'ancestor_id' => ':ancestor_id',
            ]);

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for inserting an object identity.
     */
    protected function getInsertObjectIdentitySql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->insert($this->options['oid_table_name'])
            ->values([
                'class_id' => ':class_id',
                'object_identifier' => ':object_identifier',
                'entries_inheriting' => ':entries_inheriting',
            ]);

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for inserting a security identity.
     *
     * @throws \InvalidArgumentException
     */
    protected function getInsertSecurityIdentitySql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->insert($this->options['sid_table_name'])
            ->values([
                'identifier' => ':identifier',
                'username' => ':username',
            ])
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for selecting an ACE.
     */
    protected function getSelectAccessControlEntryIdSql(?int $oid, ?string $field): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->select('id')
            ->from($this->options['entry_table_name'])
            ->where($queryBuilder->expr()->eq('class_id', ':class_id'))
            ->andWhere(
                null === $oid
                ? $queryBuilder->expr()->isNull('object_identity_id')
                : $queryBuilder->expr()->eq('object_identity_id', ':object_identity_id')
            )
            ->andWhere(
                null === $field
                    ? $queryBuilder->expr()->isNull('field_name')
                    : $queryBuilder->expr()->eq('field_name', ':field_name')
            )
            ->andWhere($queryBuilder->expr()->eq('ace_order', ':ace_order'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for selecting the primary key associated with
     * the passed class type.
     */
    protected function getSelectClassIdSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->select('id')
            ->from($this->options['class_table_name'])
            ->where($queryBuilder->expr()->eq('class_type', ':class_type'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for selecting the primary key of a security identity.
     *
     * @throws \InvalidArgumentException
     */
    protected function getSelectSecurityIdentityIdSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->select('id')
            ->from($this->options['sid_table_name'])
            ->where($queryBuilder->expr()->eq('identifier', ':identifier'))
            ->andWhere($queryBuilder->expr()->eq('username', ':username'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL to delete a security identity.
     *
     * @throws \InvalidArgumentException
     */
    protected function getDeleteSecurityIdentityIdSql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->delete($this->options['sid_table_name'])
            ->where($queryBuilder->expr()->eq('identifier', ':identifier'))
            ->andWhere($queryBuilder->expr()->eq('username', ':username'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for updating an object identity.
     *
     * @param string[] $keys
     *
     * @throws \InvalidArgumentException
     */
    protected function getUpdateObjectIdentitySql(array $keys): Statement
    {
        if (0 === \count($keys)) {
            throw new \InvalidArgumentException('There are no changes.');
        }

        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->update($this->options['oid_table_name'])
            ->where(
                $queryBuilder->expr()->eq('id', ':id')
            )
        ;

        foreach ($keys as $key) {
            $query->set($key, ':'.$key);
        }

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for updating a user security identity.
     */
    protected function getUpdateUserSecurityIdentitySql(): Statement
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->update($this->options['sid_table_name'])
            ->set('identifier', ':identifier')
            ->where($queryBuilder->expr()->eq('identifier', ':existing_identifier'))
            ->andWhere($queryBuilder->expr()->eq('username', ':username'))
        ;

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Constructs the SQL for updating an ACE.
     *
     * @param string[] $sets
     *
     * @throws \InvalidArgumentException
     */
    protected function getUpdateAccessControlEntrySql(array $keys): Statement
    {
        if (0 === \count($keys)) {
            throw new \InvalidArgumentException('There are no changes.');
        }

        $queryBuilder = $this->connection->createQueryBuilder();

        $query = $queryBuilder
            ->update($this->options['entry_table_name'])
            ->where($queryBuilder->expr()->eq('id', ':id'));

        foreach ($keys as $key) {
            $query->set($key, ':'.$key);
        }

        return $this->connection->prepare($query->getSQL());
    }

    /**
     * Creates the ACL for the passed object identity.
     */
    private function createObjectIdentity(ObjectIdentityInterface $oid): void
    {
        $classId = $this->createOrRetrieveClassId($oid->getType());

        $statement = $this->getInsertObjectIdentitySql();
        $statement->bindValue(':class_id', $classId, ParameterType::INTEGER);
        $statement->bindValue(':object_identifier', $oid->getIdentifier());
        $statement->bindValue(':entries_inheriting', true, ParameterType::BOOLEAN);
        $statement->executeStatement();
    }

    /**
     * Returns the primary key for the passed class type.
     *
     * If the type does not yet exist in the database, it will be created.
     */
    private function createOrRetrieveClassId(string $classType): int
    {
        $selectStatement = $this->getSelectClassIdSql();
        $selectStatement->bindValue(':class_type', $classType);

        if (false !== $id = $selectStatement->executeQuery()->fetchOne()) {
            return $id;
        }

        $statement = $this->getInsertClassSql();
        $statement->bindValue(':class_type', $classType);
        $statement->executeStatement();

        return $selectStatement->executeQuery()->fetchOne();
    }

    /**
     * Returns the primary key for the passed security identity.
     *
     * If the security identity does not yet exist in the database, it will be
     * created.
     */
    private function createOrRetrieveSecurityIdentityId(SecurityIdentityInterface $sid): int
    {
        if ($sid instanceof UserSecurityIdentity) {
            $identifier = $sid->getClass().'-'.$sid->getUsername();
            $username = true;
        } elseif ($sid instanceof RoleSecurityIdentity) {
            $identifier = $sid->getRole();
            $username = false;
        } else {
            throw new \InvalidArgumentException('$sid must either be an instance of UserSecurityIdentity, or RoleSecurityIdentity.');
        }

        $selectSecurityIdentityIdStatement = $this->getSelectSecurityIdentityIdSql();
        $selectSecurityIdentityIdStatement->bindValue(':identifier', $identifier);
        $selectSecurityIdentityIdStatement->bindValue(':username', $username, ParameterType::BOOLEAN);

        if (false !== $id = $selectSecurityIdentityIdStatement->executeQuery()->fetchOne()) {
            return $id;
        }

        $statement = $this->getInsertSecurityIdentitySql();
        $statement->bindValue(':identifier', $identifier);
        $statement->bindValue(':username', $username, ParameterType::BOOLEAN);
        $statement->executeStatement();

        return $selectSecurityIdentityIdStatement->executeQuery()->fetchOne();
    }

    /**
     * Deletes all ACEs for the given object identity primary key.
     */
    private function deleteAccessControlEntries(int $oidPK): void
    {
        $statement = $this->getDeleteAccessControlEntriesSql();
        $statement->bindValue('object_identity_id', $oidPK);
        $statement->executeStatement();
    }

    /**
     * Deletes the object identity from the database.
     */
    private function deleteObjectIdentity(int $pk): void
    {
        $statement = $this->getDeleteObjectIdentitySql();
        $statement->bindValue('id', $pk);
        $statement->executeStatement();
    }

    /**
     * Deletes all entries from the relations table from the database.
     */
    private function deleteObjectIdentityRelations(int $pk): void
    {
        $statement = $this->getDeleteObjectIdentityRelationsSql();
        $statement->bindValue('object_identity_id', $pk);
        $statement->executeStatement();
    }

    /**
     * This regenerates the ancestor table which is used for fast read access.
     */
    private function regenerateAncestorRelations(AclInterface $acl): void
    {
        $pk = $acl->getId();
        $this->deleteObjectIdentityRelations($pk);

        $statement = $this->getInsertObjectIdentityRelationSql();
        $statement->bindValue(':object_identity_id', $pk, ParameterType::INTEGER);
        $statement->bindValue(':ancestor_id', $pk, ParameterType::INTEGER);
        $statement->executeStatement();

        $parentAcl = $acl->getParentAcl();
        while (null !== $parentAcl) {
            $statement->bindValue(':object_identity_id', $pk, ParameterType::INTEGER);
            $statement->bindValue(':ancestor_id', $parentAcl->getId(), ParameterType::INTEGER);
            $statement->executeStatement();

            $parentAcl = $parentAcl->getParentAcl();
        }
    }

    /**
     * This processes new entries changes on an ACE related property (classFieldAces, or objectFieldAces).
     *
     * @param array<int,array<string,Entry[]>> $changes
     */
    private function updateNewFieldAceProperty(string $name, array $changes): void
    {
        $sids = new \SplObjectStorage();
        $classIds = new \SplObjectStorage();
        foreach ($changes[1] as $field => $new) {
            for ($i = 0, $c = \count($new); $i < $c; ++$i) {
                $ace = $new[$i];

                if (null === $ace->getId()) {
                    if ($sids->contains($ace->getSecurityIdentity())) {
                        $sid = $sids->offsetGet($ace->getSecurityIdentity());
                    } else {
                        $sid = $this->createOrRetrieveSecurityIdentityId($ace->getSecurityIdentity());
                    }

                    $oid = $ace->getAcl()->getObjectIdentity();
                    if ($classIds->contains($oid)) {
                        $classId = $classIds->offsetGet($oid);
                    } else {
                        $classId = $this->createOrRetrieveClassId($oid->getType());
                    }

                    $objectIdentityId = 'classFieldAces' === $name ? null : $ace->getAcl()->getId();

                    $insertAccessControlEntryStatement = $this->getInsertAccessControlEntrySql();
                    $insertAccessControlEntryStatement->bindValue(':class_id', $classId, ParameterType::INTEGER);
                    $insertAccessControlEntryStatement->bindValue(':object_identity_id', $objectIdentityId, null === $objectIdentityId ? ParameterType::NULL : ParameterType::INTEGER);
                    $insertAccessControlEntryStatement->bindValue(':field_name', $field, null === $field ? ParameterType::NULL : ParameterType::STRING);
                    $insertAccessControlEntryStatement->bindValue(':ace_order', $i, ParameterType::INTEGER);
                    $insertAccessControlEntryStatement->bindValue(':security_identity_id', $sid, ParameterType::INTEGER);
                    $insertAccessControlEntryStatement->bindValue(':mask', $ace->getMask(), ParameterType::INTEGER);
                    $insertAccessControlEntryStatement->bindValue(':granting', $ace->isGranting(), ParameterType::BOOLEAN);
                    $insertAccessControlEntryStatement->bindValue(':granting_strategy', $ace->getStrategy());
                    $insertAccessControlEntryStatement->bindValue(':audit_success', $ace->isAuditSuccess(), ParameterType::BOOLEAN);
                    $insertAccessControlEntryStatement->bindValue(':audit_failure', $ace->isAuditFailure(), ParameterType::BOOLEAN);
                    $insertAccessControlEntryStatement->executeStatement();

                    $selectAccessControlEntryIdStatement = $this->getSelectAccessControlEntryIdSql($objectIdentityId, $field);
                    $selectAccessControlEntryIdStatement->bindValue(':class_id', $classId);
                    $selectAccessControlEntryIdStatement->bindValue(':ace_order', $i);

                    if (\is_int($objectIdentityId)) {
                        $selectAccessControlEntryIdStatement->bindValue(':object_identity_id', $objectIdentityId, ParameterType::INTEGER);
                    }

                    if (\is_string($field)) {
                        $selectAccessControlEntryIdStatement->bindValue(':field_name', $field);
                    }

                    $aceId = $selectAccessControlEntryIdStatement->executeQuery()->fetchOne();

                    $this->loadedAces[$aceId] = $ace;

                    $aceIdProperty = new \ReflectionProperty(Entry::class, 'id');
                    $aceIdProperty->setAccessible(true);
                    $aceIdProperty->setValue($ace, (int) $aceId);
                }
            }
        }
    }

    /**
     * This processes old entries changes on an ACE related property (classFieldAces, or objectFieldAces).
     *
     * @param array<int,array<string,Entry[]>> $changes
     */
    private function updateOldFieldAceProperty(string $name, array $changes): void
    {
        $currentIds = [];
        foreach ($changes[1] as $field => $new) {
            for ($i = 0, $c = \count($new); $i < $c; ++$i) {
                $ace = $new[$i];

                if (null !== $ace->getId()) {
                    $currentIds[$ace->getId()] = true;
                }
            }
        }

        foreach ($changes[0] as $old) {
            for ($i = 0, $c = \count($old); $i < $c; ++$i) {
                $ace = $old[$i];

                if (!isset($currentIds[$ace->getId()])) {
                    $statement = $this->getDeleteAccessControlEntrySql();
                    $statement->bindValue('id', $ace->getId(), ParameterType::INTEGER);
                    $statement->executeStatement();

                    unset($this->loadedAces[$ace->getId()]);
                }
            }
        }
    }

    /**
     * This processes new entries changes on an ACE related property (classAces, or objectAces).
     *
     * @param array<int,Entry[]> $changes
     */
    private function updateNewAceProperty(string $name, array $changes): void
    {
        [$old, $new] = $changes;

        $sids = new \SplObjectStorage();
        $classIds = new \SplObjectStorage();
        for ($i = 0, $c = \count($new); $i < $c; ++$i) {
            $ace = $new[$i];

            if (null === $ace->getId()) {
                if ($sids->contains($ace->getSecurityIdentity())) {
                    $sid = $sids->offsetGet($ace->getSecurityIdentity());
                } else {
                    $sid = $this->createOrRetrieveSecurityIdentityId($ace->getSecurityIdentity());
                }

                $oid = $ace->getAcl()->getObjectIdentity();
                if ($classIds->contains($oid)) {
                    $classId = $classIds->offsetGet($oid);
                } else {
                    $classId = $this->createOrRetrieveClassId($oid->getType());
                }

                $objectIdentityId = 'classAces' === $name ? null : $ace->getAcl()->getId();

                $statement = $this->getInsertAccessControlEntrySql();
                $statement->bindValue(':class_id', $classId, ParameterType::INTEGER);
                $statement->bindValue(':object_identity_id', $objectIdentityId, null === $objectIdentityId ? ParameterType::NULL : ParameterType::INTEGER);
                $statement->bindValue(':field_name', null, ParameterType::NULL);
                $statement->bindValue(':ace_order', $i, ParameterType::INTEGER);
                $statement->bindValue(':security_identity_id', $sid, ParameterType::INTEGER);
                $statement->bindValue(':mask', $ace->getMask(), ParameterType::INTEGER);
                $statement->bindValue(':granting', $ace->isGranting(), ParameterType::BOOLEAN);
                $statement->bindValue(':granting_strategy', $ace->getStrategy());
                $statement->bindValue(':audit_success', $ace->isAuditSuccess(), ParameterType::BOOLEAN);
                $statement->bindValue(':audit_failure', $ace->isAuditFailure(), ParameterType::BOOLEAN);
                $statement->executeStatement();

                $selectAccessControlEntryIdStatement = $this->getSelectAccessControlEntryIdSql($objectIdentityId, null);
                $selectAccessControlEntryIdStatement->bindValue(':class_id', $classId);
                $selectAccessControlEntryIdStatement->bindValue(':ace_order', $i);

                if (\is_int($objectIdentityId)) {
                    $selectAccessControlEntryIdStatement->bindValue(':object_identity_id', $objectIdentityId, ParameterType::INTEGER);
                }

                $aceId = $selectAccessControlEntryIdStatement->executeQuery()->fetchOne();

                $this->loadedAces[$aceId] = $ace;

                $aceIdProperty = new \ReflectionProperty($ace, 'id');
                $aceIdProperty->setAccessible(true);
                $aceIdProperty->setValue($ace, (int) $aceId);
            }
        }
    }

    /**
     * This processes old entries changes on an ACE related property (classAces, or objectAces).
     *
     * @param array<int,Entry[]> $changes
     */
    private function updateOldAceProperty(string $name, array $changes): void
    {
        [$old, $new] = $changes;
        $currentIds = [];

        for ($i = 0, $c = \count($new); $i < $c; ++$i) {
            $ace = $new[$i];

            if (null !== $ace->getId()) {
                $currentIds[$ace->getId()] = true;
            }
        }

        for ($i = 0, $c = \count($old); $i < $c; ++$i) {
            $ace = $old[$i];

            if (!isset($currentIds[$ace->getId()])) {
                $statement = $this->getDeleteAccessControlEntrySql();
                $statement->bindValue('id', $ace->getId(), ParameterType::INTEGER);
                $statement->executeStatement();

                unset($this->loadedAces[$ace->getId()]);
            }
        }
    }

    /**
     * Persists the changes which were made to ACEs to the database.
     *
     * @param \SplObjectStorage<EntryInterface,array<string,mixed>> $aces
     */
    private function updateAces(\SplObjectStorage $aces): void
    {
        foreach ($aces as $ace) {
            $this->updateAce($aces, $ace);
        }
    }

    /**
     * @param \SplObjectStorage<EntryInterface,array<string,mixed>> $aces
     */
    private function updateAce(\SplObjectStorage $aces, EntryInterface $ace): void
    {
        $propertyChanges = $aces->offsetGet($ace);
        $sets = [];

        if (isset($propertyChanges['aceOrder'])
            && $propertyChanges['aceOrder'][1] > $propertyChanges['aceOrder'][0]
            && $propertyChanges == $aces->offsetGet($ace)) {
            $aces->next();
            if ($aces->valid()) {
                $this->updateAce($aces, $aces->current());
            }
        }

        if (isset($propertyChanges['mask'])) {
            $sets[] = ['key' => 'mask', 'value' => $propertyChanges['mask'][1], 'type' => ParameterType::INTEGER];
        }
        if (isset($propertyChanges['strategy'])) {
            $sets[] = ['key' => 'strategy', 'value' => $propertyChanges['strategy'][1], 'type' => ParameterType::STRING];
        }
        if (isset($propertyChanges['aceOrder'])) {
            $sets[] = ['key' => 'ace_order', 'value' => $propertyChanges['aceOrder'][1], 'type' => ParameterType::INTEGER];
        }
        if (isset($propertyChanges['auditSuccess'])) {
            $sets[] = ['key' => 'audit_success', 'value' => $propertyChanges['auditSuccess'][1], 'type' => ParameterType::BOOLEAN];
        }
        if (isset($propertyChanges['auditFailure'])) {
            $sets[] = ['key' => 'audit_failure', 'value' => $propertyChanges['auditFailure'][1], 'type' => ParameterType::BOOLEAN];
        }

        $statement = $this->getUpdateAccessControlEntrySql(array_column($sets, 'key'));
        $statement->bindValue(':id', $ace->getId(), ParameterType::INTEGER);

        foreach ($sets as $set) {
            $statement->bindValue(':'.$set['key'], $set['value'], $set['type']);
        }

        $statement->executeStatement();
    }
}
