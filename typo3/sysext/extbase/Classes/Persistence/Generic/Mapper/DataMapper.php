<?php
namespace TYPO3\CMS\Extbase\Persistence\Generic\Mapper;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Object\Exception\CannotReconstituteObjectException;
use TYPO3\CMS\Extbase\Persistence;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\UnexpectedTypeException;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Utility\TypeHandlingUtility;

/**
 * A mapper to map database tables configured in $TCA on domain objects.
 * @internal only to be used within Extbase, not part of TYPO3 Core API.
 */
class DataMapper
{
    /**
     * @var \TYPO3\CMS\Extbase\Reflection\ReflectionService
     */
    protected $reflectionService;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Qom\QueryObjectModelFactory
     */
    protected $qomFactory;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Session
     */
    protected $persistenceSession;

    /**
     * A reference to the page select object providing methods to perform language and work space overlays
     *
     * @var \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected $pageSelectObject;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory
     */
    protected $dataMapFactory;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\QueryFactoryInterface
     */
    protected $queryFactory;

    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * @var ?QueryInterface
     */
    protected $query;

    /**
     * DataMapper constructor.
     * @param \TYPO3\CMS\Extbase\Reflection\ReflectionService $reflectionService
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\Qom\QueryObjectModelFactory $qomFactory
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\Session $persistenceSession
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory $dataMapFactory
     * @param \TYPO3\CMS\Extbase\Persistence\Generic\QueryFactoryInterface $queryFactory
     * @param \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager
     * @param \TYPO3\CMS\Extbase\SignalSlot\Dispatcher $signalSlotDispatcher
     * @param QueryInterface|null $query
     */
    public function __construct(
        \TYPO3\CMS\Extbase\Reflection\ReflectionService $reflectionService,
        \TYPO3\CMS\Extbase\Persistence\Generic\Qom\QueryObjectModelFactory $qomFactory,
        \TYPO3\CMS\Extbase\Persistence\Generic\Session $persistenceSession,
        \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory $dataMapFactory,
        \TYPO3\CMS\Extbase\Persistence\Generic\QueryFactoryInterface $queryFactory,
        \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager,
        \TYPO3\CMS\Extbase\SignalSlot\Dispatcher $signalSlotDispatcher,
        ?QueryInterface $query = null
    ) {
        $this->query = $query;
        $this->reflectionService = $reflectionService;
        $this->qomFactory = $qomFactory;
        $this->persistenceSession = $persistenceSession;
        $this->dataMapFactory = $dataMapFactory;
        $this->queryFactory = $queryFactory;
        $this->objectManager = $objectManager;
        $this->signalSlotDispatcher = $signalSlotDispatcher;

        if ($query !== null) {
            trigger_error(
                'Constructor argument $query will be removed in TYPO3 v11.0, use setQuery method instead.',
                E_USER_DEPRECATED
            );
            $this->query = $query;
        }
    }

    /**
     * @param QueryInterface $query
     */
    public function setQuery(QueryInterface $query): void
    {
        $this->query = $query;
    }

    /**
     * Maps the given rows on objects
     *
     * @param string $className The name of the class
     * @param array $rows An array of arrays with field_name => value pairs
     * @return array An array of objects of the given class
     */
    public function map($className, array $rows)
    {
        $objects = [];
        foreach ($rows as $row) {
            $objects[] = $this->mapSingleRow($this->getTargetType($className, $row), $row);
        }
        return $objects;
    }

    /**
     * Returns the target type for the given row.
     *
     * @param string $className The name of the class
     * @param array $row A single array with field_name => value pairs
     * @return string The target type (a class name)
     */
    public function getTargetType($className, array $row)
    {
        $dataMap = $this->getDataMap($className);
        $targetType = $className;
        if ($dataMap->getRecordTypeColumnName() !== null) {
            foreach ($dataMap->getSubclasses() as $subclassName) {
                $recordSubtype = $this->getDataMap($subclassName)->getRecordType();
                if ((string)$row[$dataMap->getRecordTypeColumnName()] === (string)$recordSubtype) {
                    $targetType = $subclassName;
                    break;
                }
            }
        }
        return $targetType;
    }

    /**
     * Maps a single row on an object of the given class
     *
     * @param string $className The name of the target class
     * @param array $row A single array with field_name => value pairs
     * @return object An object of the given class
     */
    protected function mapSingleRow($className, array $row)
    {
        if ($this->persistenceSession->hasIdentifier($row['uid'], $className)) {
            $object = $this->persistenceSession->getObjectByIdentifier($row['uid'], $className);
        } else {
            $object = $this->createEmptyObject($className);
            $this->persistenceSession->registerObject($object, $row['uid']);
            $this->thawProperties($object, $row);
            $this->emitAfterMappingSingleRow($object);
            $object->_memorizeCleanState();
            $this->persistenceSession->registerReconstitutedEntity($object);
        }
        return $object;
    }

    /**
     * Emits a signal after mapping a single row.
     *
     * @param DomainObjectInterface $object The mapped object
     */
    protected function emitAfterMappingSingleRow(DomainObjectInterface $object)
    {
        $this->signalSlotDispatcher->dispatch(__CLASS__, 'afterMappingSingleRow', [$object]);
    }

    /**
     * Creates a skeleton of the specified object
     *
     * @param string $className Name of the class to create a skeleton for
     * @throws CannotReconstituteObjectException
     * @return object The object skeleton
     */
    protected function createEmptyObject($className)
    {
        // Note: The class_implements() function also invokes autoload to assure that the interfaces
        // and the class are loaded. Would end up with __PHP_Incomplete_Class without it.
        if (!in_array(\TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface::class, class_implements($className))) {
            throw new CannotReconstituteObjectException('Cannot create empty instance of the class "' . $className
                . '" because it does not implement the TYPO3\\CMS\\Extbase\\DomainObject\\DomainObjectInterface.', 1234386924);
        }
        $object = $this->objectManager->getEmptyObject($className);
        return $object;
    }

    /**
     * Sets the given properties on the object.
     *
     * @param DomainObjectInterface $object The object to set properties on
     * @param array $row
     */
    protected function thawProperties(DomainObjectInterface $object, array $row)
    {
        $className = get_class($object);
        $classSchema = $this->reflectionService->getClassSchema($className);
        $dataMap = $this->getDataMap($className);
        $object->_setProperty('uid', (int)$row['uid']);
        $object->_setProperty('pid', (int)($row['pid'] ?? 0));
        $object->_setProperty('_localizedUid', (int)$row['uid']);
        $object->_setProperty('_versionedUid', (int)$row['uid']);
        if ($dataMap->getLanguageIdColumnName() !== null) {
            $object->_setProperty('_languageUid', (int)$row[$dataMap->getLanguageIdColumnName()]);
            if (isset($row['_LOCALIZED_UID'])) {
                $object->_setProperty('_localizedUid', (int)$row['_LOCALIZED_UID']);
            }
        }
        if (!empty($row['_ORIG_uid']) && !empty($GLOBALS['TCA'][$dataMap->getTableName()]['ctrl']['versioningWS'])) {
            $object->_setProperty('_versionedUid', (int)$row['_ORIG_uid']);
        }
        $properties = $object->_getProperties();
        foreach ($properties as $propertyName => $propertyValue) {
            if (!$dataMap->isPersistableProperty($propertyName)) {
                continue;
            }
            $columnMap = $dataMap->getColumnMap($propertyName);
            $columnName = $columnMap->getColumnName();
            $propertyData = $classSchema->getProperty($propertyName);
            $propertyValue = null;
            if (isset($row[$columnName])) {
                switch ($propertyData['type']) {
                    case 'integer':
                        $propertyValue = (int)$row[$columnName];
                        break;
                    case 'float':
                        $propertyValue = (double)$row[$columnName];
                        break;
                    case 'boolean':
                        $propertyValue = (bool)$row[$columnName];
                        break;
                    case 'string':
                        $propertyValue = (string)$row[$columnName];
                        break;
                    case 'array':
                        // $propertyValue = $this->mapArray($row[$columnName]); // Not supported, yet!
                        break;
                    case 'SplObjectStorage':
                    case Persistence\ObjectStorage::class:
                        $propertyValue = $this->mapResultToPropertyValue(
                            $object,
                            $propertyName,
                            $this->fetchRelated($object, $propertyName, $row[$columnName])
                        );
                        break;
                    case is_subclass_of($propertyData['type'], \DateTimeInterface::class):
                            $propertyValue = $this->mapDateTime(
                                $row[$columnName],
                                $columnMap->getDateTimeStorageFormat(),
                                $propertyData['type']
                            );
                        break;
                    default:
                        if (TypeHandlingUtility::isCoreType($propertyData['type'])) {
                            $propertyValue = $this->mapCoreType($propertyData['type'], $row[$columnName]);
                        } else {
                            $propertyValue = $this->mapObjectToClassProperty(
                                $object,
                                $propertyName,
                                $row[$columnName]
                            );
                        }

                }
            }
            if ($propertyValue !== null) {
                $object->_setProperty($propertyName, $propertyValue);
            }
        }
    }

    /**
     * Map value to a core type
     *
     * @param string $type
     * @param mixed $value
     * @return \TYPO3\CMS\Core\Type\TypeInterface
     */
    protected function mapCoreType($type, $value)
    {
        return new $type($value);
    }

    /**
     * Creates a DateTime from an unix timestamp or date/datetime/time value.
     * If the input is empty, NULL is returned.
     *
     * @param int|string $value Unix timestamp or date/datetime/time value
     * @param string|null $storageFormat Storage format for native date/datetime/time fields
     * @param string|null $targetType The object class name to be created
     * @return \DateTimeInterface
     */
    protected function mapDateTime($value, $storageFormat = null, $targetType = \DateTime::class)
    {
        $dateTimeTypes = QueryHelper::getDateTimeTypes();

        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00' || $value === '00:00:00') {
            // 0 -> NULL !!!
            return null;
        }
        if (in_array($storageFormat, $dateTimeTypes, true)) {
            // native date/datetime/time values are stored in UTC
            $utcTimeZone = new \DateTimeZone('UTC');
            $utcDateTime = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance($targetType, $value, $utcTimeZone);
            $currentTimeZone = new \DateTimeZone(date_default_timezone_get());
            return $utcDateTime->setTimezone($currentTimeZone);
        }
        // integer timestamps are local server time
        return \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance($targetType, date('c', (int)$value));
    }

    /**
     * Fetches a collection of objects related to a property of a parent object
     *
     * @param DomainObjectInterface $parentObject The object instance this proxy is part of
     * @param string $propertyName The name of the proxied property in it's parent
     * @param mixed $fieldValue The raw field value.
     * @param bool $enableLazyLoading A flag indication if the related objects should be lazy loaded
     * @return \TYPO3\CMS\Extbase\Persistence\Generic\LazyObjectStorage|Persistence\QueryResultInterface The result
     */
    public function fetchRelated(DomainObjectInterface $parentObject, $propertyName, $fieldValue = '', $enableLazyLoading = true)
    {
        $propertyMetaData = $this->reflectionService->getClassSchema(get_class($parentObject))->getProperty($propertyName);
        if ($enableLazyLoading === true && $propertyMetaData['annotations']['lazy']) {
            if ($propertyMetaData['type'] === Persistence\ObjectStorage::class) {
                $result = $this->objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\LazyObjectStorage::class, $parentObject, $propertyName, $fieldValue, $this);
            } else {
                if (empty($fieldValue)) {
                    $result = null;
                } else {
                    $result = $this->objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy::class, $parentObject, $propertyName, $fieldValue, $this);
                }
            }
        } else {
            $result = $this->fetchRelatedEager($parentObject, $propertyName, $fieldValue);
        }
        return $result;
    }

    /**
     * Fetches the related objects from the storage backend.
     *
     * @param DomainObjectInterface $parentObject The object instance this proxy is part of
     * @param string $propertyName The name of the proxied property in it's parent
     * @param mixed $fieldValue The raw field value.
     * @return mixed
     */
    protected function fetchRelatedEager(DomainObjectInterface $parentObject, $propertyName, $fieldValue = '')
    {
        return $fieldValue === '' ? $this->getEmptyRelationValue($parentObject, $propertyName) : $this->getNonEmptyRelationValue($parentObject, $propertyName, $fieldValue);
    }

    /**
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @return array|null
     */
    protected function getEmptyRelationValue(DomainObjectInterface $parentObject, $propertyName)
    {
        $columnMap = $this->getDataMap(get_class($parentObject))->getColumnMap($propertyName);
        $relatesToOne = $columnMap->getTypeOfRelation() == ColumnMap::RELATION_HAS_ONE;
        return $relatesToOne ? null : [];
    }

    /**
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @param string $fieldValue
     * @return Persistence\QueryResultInterface
     */
    protected function getNonEmptyRelationValue(DomainObjectInterface $parentObject, $propertyName, $fieldValue)
    {
        $query = $this->getPreparedQuery($parentObject, $propertyName, $fieldValue);
        return $query->execute();
    }

    /**
     * Builds and returns the prepared query, ready to be executed.
     *
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @param string $fieldValue
     * @return Persistence\QueryInterface
     */
    protected function getPreparedQuery(DomainObjectInterface $parentObject, $propertyName, $fieldValue = '')
    {
        $dataMap = $this->getDataMap(get_class($parentObject));
        $columnMap = $dataMap->getColumnMap($propertyName);
        $type = $this->getType(get_class($parentObject), $propertyName);
        $query = $this->queryFactory->create($type);
        if ($this->query && $query instanceof Persistence\Generic\Query) {
            $query->setParentQuery($this->query);
        }
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->getQuerySettings()->setRespectSysLanguage(false);

        // we always want to overlay relations as most of the time they are stored in db using default lang uids
        $query->getQuerySettings()->setLanguageOverlayMode(true);
        if ($this->query) {
            $query->getQuerySettings()->setLanguageUid($this->query->getQuerySettings()->getLanguageUid());

            if ($dataMap->getLanguageIdColumnName() !== null && !$this->query->getQuerySettings()->getRespectSysLanguage()) {
                //pass language of parent record to child objects, so they can be overlaid correctly in case
                //e.g. findByUid is used.
                //the languageUid is used for getRecordOverlay later on, despite RespectSysLanguage being false
                $languageUid = (int)$parentObject->_getProperty('_languageUid');
                $query->getQuerySettings()->setLanguageUid($languageUid);
            }
        }

        if ($columnMap->getTypeOfRelation() === ColumnMap::RELATION_HAS_MANY) {
            if ($columnMap->getChildSortByFieldName() !== null) {
                $query->setOrderings([$columnMap->getChildSortByFieldName() => Persistence\QueryInterface::ORDER_ASCENDING]);
            }
        } elseif ($columnMap->getTypeOfRelation() === ColumnMap::RELATION_HAS_AND_BELONGS_TO_MANY) {
            $query->setSource($this->getSource($parentObject, $propertyName));
            if ($columnMap->getChildSortByFieldName() !== null) {
                $query->setOrderings([$columnMap->getChildSortByFieldName() => Persistence\QueryInterface::ORDER_ASCENDING]);
            }
        }
        $query->matching($this->getConstraint($query, $parentObject, $propertyName, $fieldValue, $columnMap->getRelationTableMatchFields()));
        return $query;
    }

    /**
     * Builds and returns the constraint for multi value properties.
     *
     * @param Persistence\QueryInterface $query
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @param string $fieldValue
     * @param array $relationTableMatchFields
     * @return \TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface $constraint
     */
    protected function getConstraint(Persistence\QueryInterface $query, DomainObjectInterface $parentObject, $propertyName, $fieldValue = '', $relationTableMatchFields = [])
    {
        $columnMap = $this->getDataMap(get_class($parentObject))->getColumnMap($propertyName);
        if ($columnMap->getParentKeyFieldName() !== null) {
            $constraint = $query->equals($columnMap->getParentKeyFieldName(), $parentObject);
            if ($columnMap->getParentTableFieldName() !== null) {
                $constraint = $query->logicalAnd(
                    $constraint,
                    $query->equals($columnMap->getParentTableFieldName(), $this->getDataMap(get_class($parentObject))->getTableName())
                );
            }
        } else {
            $constraint = $query->in('uid', \TYPO3\CMS\Core\Utility\GeneralUtility::intExplode(',', $fieldValue));
        }
        if (!empty($relationTableMatchFields)) {
            foreach ($relationTableMatchFields as $relationTableMatchFieldName => $relationTableMatchFieldValue) {
                $constraint = $query->logicalAnd($constraint, $query->equals($relationTableMatchFieldName, $relationTableMatchFieldValue));
            }
        }
        return $constraint;
    }

    /**
     * Builds and returns the source to build a join for a m:n relation.
     *
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @return \TYPO3\CMS\Extbase\Persistence\Generic\Qom\SourceInterface $source
     */
    protected function getSource(DomainObjectInterface $parentObject, $propertyName)
    {
        $columnMap = $this->getDataMap(get_class($parentObject))->getColumnMap($propertyName);
        $left = $this->qomFactory->selector(null, $columnMap->getRelationTableName());
        $childClassName = $this->getType(get_class($parentObject), $propertyName);
        $right = $this->qomFactory->selector($childClassName, $columnMap->getChildTableName());
        $joinCondition = $this->qomFactory->equiJoinCondition($columnMap->getRelationTableName(), $columnMap->getChildKeyFieldName(), $columnMap->getChildTableName(), 'uid');
        $source = $this->qomFactory->join($left, $right, Persistence\Generic\Query::JCR_JOIN_TYPE_INNER, $joinCondition);
        return $source;
    }

    /**
     * Returns the mapped classProperty from the identiyMap or
     * mapResultToPropertyValue()
     *
     * If the field value is empty and the column map has no parent key field name,
     * the relation will be empty. If the persistence session has a registered object of
     * the correct type and identity (fieldValue), this function returns that object.
     * Otherwise, it proceeds with mapResultToPropertyValue().
     *
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @param mixed $fieldValue the raw field value
     * @return mixed
     * @see mapResultToPropertyValue()
     */
    protected function mapObjectToClassProperty(DomainObjectInterface $parentObject, $propertyName, $fieldValue)
    {
        if ($this->propertyMapsByForeignKey($parentObject, $propertyName)) {
            $result = $this->fetchRelated($parentObject, $propertyName, $fieldValue);
            $propertyValue = $this->mapResultToPropertyValue($parentObject, $propertyName, $result);
        } else {
            if ($fieldValue === '') {
                $propertyValue = $this->getEmptyRelationValue($parentObject, $propertyName);
            } else {
                $propertyMetaData = $this->reflectionService->getClassSchema(get_class($parentObject))->getProperty($propertyName);
                if ($this->persistenceSession->hasIdentifier($fieldValue, $propertyMetaData['type'])) {
                    $propertyValue = $this->persistenceSession->getObjectByIdentifier($fieldValue, $propertyMetaData['type']);
                } else {
                    $result = $this->fetchRelated($parentObject, $propertyName, $fieldValue);
                    $propertyValue = $this->mapResultToPropertyValue($parentObject, $propertyName, $result);
                }
            }
        }

        return $propertyValue;
    }

    /**
     * Checks if the relation is based on a foreign key.
     *
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @return bool TRUE if the property is mapped
     */
    protected function propertyMapsByForeignKey(DomainObjectInterface $parentObject, $propertyName)
    {
        $columnMap = $this->getDataMap(get_class($parentObject))->getColumnMap($propertyName);
        return $columnMap->getParentKeyFieldName() !== null;
    }

    /**
     * Returns the given result as property value of the specified property type.
     *
     * @param DomainObjectInterface $parentObject
     * @param string $propertyName
     * @param mixed $result The result
     * @return mixed
     */
    public function mapResultToPropertyValue(DomainObjectInterface $parentObject, $propertyName, $result)
    {
        $propertyValue = null;
        if ($result instanceof Persistence\Generic\LoadingStrategyInterface) {
            $propertyValue = $result;
        } else {
            $propertyMetaData = $this->reflectionService->getClassSchema(get_class($parentObject))->getProperty($propertyName);
            if (in_array($propertyMetaData['type'], ['array', 'ArrayObject', 'SplObjectStorage', Persistence\ObjectStorage::class], true)) {
                $objects = [];
                foreach ($result as $value) {
                    $objects[] = $value;
                }
                if ($propertyMetaData['type'] === 'ArrayObject') {
                    $propertyValue = new \ArrayObject($objects);
                } elseif ($propertyMetaData['type'] === Persistence\ObjectStorage::class) {
                    $propertyValue = new Persistence\ObjectStorage();
                    foreach ($objects as $object) {
                        $propertyValue->attach($object);
                    }
                    $propertyValue->_memorizeCleanState();
                } else {
                    $propertyValue = $objects;
                }
            } elseif (strpbrk($propertyMetaData['type'], '_\\') !== false) {
                if (is_object($result) && $result instanceof Persistence\QueryResultInterface) {
                    $propertyValue = $result->getFirst();
                } else {
                    $propertyValue = $result;
                }
            }
        }
        return $propertyValue;
    }

    /**
     * Counts the number of related objects assigned to a property of a parent object
     *
     * @param DomainObjectInterface $parentObject The object instance this proxy is part of
     * @param string $propertyName The name of the proxied property in it's parent
     * @param mixed $fieldValue The raw field value.
     * @return int
     */
    public function countRelated(DomainObjectInterface $parentObject, $propertyName, $fieldValue = '')
    {
        $query = $this->getPreparedQuery($parentObject, $propertyName, $fieldValue);
        return $query->execute()->count();
    }

    /**
     * Delegates the call to the Data Map.
     * Returns TRUE if the property is persistable (configured in $TCA)
     *
     * @param string $className The property name
     * @param string $propertyName The property name
     * @return bool TRUE if the property is persistable (configured in $TCA)
     */
    public function isPersistableProperty($className, $propertyName)
    {
        $dataMap = $this->getDataMap($className);
        return $dataMap->isPersistableProperty($propertyName);
    }

    /**
     * Returns a data map for a given class name
     *
     * @param string $className The class name you want to fetch the Data Map for
     * @throws Persistence\Generic\Exception
     * @return DataMap The data map
     */
    public function getDataMap($className)
    {
        if (!is_string($className) || $className === '') {
            throw new Persistence\Generic\Exception('No class name was given to retrieve the Data Map for.', 1251315965);
        }
        return $this->dataMapFactory->buildDataMap($className);
    }

    /**
     * Returns the selector (table) name for a given class name.
     *
     * @param string $className
     * @return string The selector name
     */
    public function convertClassNameToTableName($className)
    {
        return $this->getDataMap($className)->getTableName();
    }

    /**
     * Returns the column name for a given property name of the specified class.
     *
     * @param string $propertyName
     * @param string $className
     * @return string The column name
     */
    public function convertPropertyNameToColumnName($propertyName, $className = null)
    {
        if (!empty($className)) {
            $dataMap = $this->getDataMap($className);
            if ($dataMap !== null) {
                $columnMap = $dataMap->getColumnMap($propertyName);
                if ($columnMap !== null) {
                    return $columnMap->getColumnName();
                }
            }
        }
        return \TYPO3\CMS\Core\Utility\GeneralUtility::camelCaseToLowerCaseUnderscored($propertyName);
    }

    /**
     * Returns the type of a child object.
     *
     * @param string $parentClassName The class name of the object this proxy is part of
     * @param string $propertyName The name of the proxied property in it's parent
     * @throws UnexpectedTypeException
     * @return string The class name of the child object
     */
    public function getType($parentClassName, $propertyName)
    {
        $propertyMetaData = $this->reflectionService->getClassSchema($parentClassName)->getProperty($propertyName);
        if (!empty($propertyMetaData['elementType'])) {
            $type = $propertyMetaData['elementType'];
        } elseif (!empty($propertyMetaData['type'])) {
            $type = $propertyMetaData['type'];
        } else {
            throw new UnexpectedTypeException('Could not determine the child object type.', 1251315967);
        }
        return $type;
    }

    /**
     * Returns a plain value, i.e. objects are flattened out if possible.
     * Multi value objects or arrays will be converted to a comma-separated list for use in IN SQL queries.
     *
     * @param mixed $input The value that will be converted.
     * @param ColumnMap $columnMap Optional column map for retrieving the date storage format.
     * @throws \InvalidArgumentException
     * @throws UnexpectedTypeException
     * @return int|string
     */
    public function getPlainValue($input, $columnMap = null)
    {
        if ($input === null) {
            return 'NULL';
        }
        if ($input instanceof Persistence\Generic\LazyLoadingProxy) {
            $input = $input->_loadRealInstance();
        }

        if (is_bool($input)) {
            $parameter = (int)$input;
        } elseif (is_int($input)) {
            $parameter = $input;
        } elseif ($input instanceof \DateTimeInterface) {
            if ($columnMap !== null && $columnMap->getDateTimeStorageFormat() !== null) {
                $storageFormat = $columnMap->getDateTimeStorageFormat();
                $timeZoneToStore = clone $input;
                // set to UTC to store in database
                $timeZoneToStore->setTimezone(new \DateTimeZone('UTC'));
                switch ($storageFormat) {
                    case 'datetime':
                        $parameter = $timeZoneToStore->format('Y-m-d H:i:s');
                        break;
                    case 'date':
                        $parameter = $timeZoneToStore->format('Y-m-d');
                        break;
                    case 'time':
                        $parameter = $timeZoneToStore->format('H:i');
                        break;
                    default:
                        throw new \InvalidArgumentException('Column map DateTime format "' . $storageFormat . '" is unknown. Allowed values are date, datetime or time.', 1395353470);
                }
            } else {
                $parameter = $input->format('U');
            }
        } elseif ($input instanceof DomainObjectInterface) {
            $parameter = (int)$input->getUid();
        } elseif (TypeHandlingUtility::isValidTypeForMultiValueComparison($input)) {
            $plainValueArray = [];
            foreach ($input as $inputElement) {
                $plainValueArray[] = $this->getPlainValue($inputElement, $columnMap);
            }
            $parameter = implode(',', $plainValueArray);
        } elseif (is_object($input)) {
            if (TypeHandlingUtility::isCoreType($input)) {
                $parameter = (string)$input;
            } else {
                throw new UnexpectedTypeException('An object of class "' . get_class($input) . '" could not be converted to a plain value.', 1274799934);
            }
        } else {
            $parameter = (string)$input;
        }
        return $parameter;
    }
}
