<?php
/*
 * This file is part of the Sidus/EAVModelBundle package.
 *
 * Copyright (c) 2015-2018 Vincent Chalnot
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sidus\EAVModelBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sidus\EAVModelBundle\Registry\FamilyRegistry;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * See command description
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class PurgeOrphanDataCommand extends ContainerAwareCommand
{
    /** @var FamilyRegistry */
    protected $familyRegistry;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var string */
    protected $dataClass;

    /** @var string */
    protected $valueClass;

    /**
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        $description = 'Purges all the data with a missing families and all the values with missing attributes';
        $this
            ->setName('sidus:data:purge-orphan-data')
            ->setDescription($description);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     * @throws \LogicException
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->familyRegistry = $this->getContainer()->get(FamilyRegistry::class);
        $this->entityManager = $this->getContainer()->get('sidus_eav_model.entity_manager');
        $this->dataClass = $this->getContainer()->getParameter('sidus_eav_model.entity.data.class');
        $this->valueClass = $this->getContainer()->getParameter('sidus_eav_model.entity.value.class');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->purgeMissingFamilies($output);
        $this->purgeMissingAttributes($output);
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function purgeMissingFamilies(OutputInterface $output)
    {
        $metadata = $this->entityManager->getClassMetadata($this->dataClass);
        $table = $metadata->getTableName();
        $flattenedFamilyCodes = $this->quoteArray($this->familyRegistry->getFamilyCodes());

        // LIMIT is not natively supported for delete statements in Doctrine
        $sql = "DELETE FROM `{$table}` WHERE family_code NOT IN ({$flattenedFamilyCodes}) LIMIT 1000";

        $count = $this->executeWithPaging($sql);

        if ($count) {
            $output->writeln("<comment>{$count} data purged with missing family</comment>");
        } else {
            $output->writeln('<info>No data to purge</info>');
        }
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function purgeMissingAttributes(OutputInterface $output)
    {
        $metadata = $this->entityManager->getClassMetadata($this->valueClass);
        $table = $metadata->getTableName();

        foreach ($this->familyRegistry->getFamilies() as $family) {
            $attributeCodes = [];
            foreach ($family->getAttributes() as $attribute) {
                $attributeCodes[] = $attribute->getCode();
            }

            $quotedFamilyCode = $this->entityManager->getConnection()->quote($family->getCode());
            $flattenedAttributeCodes = $this->quoteArray($attributeCodes);

            // LIMIT is not natively supported for delete statements in Doctrine
            $sql = "DELETE FROM `{$table}` WHERE family_code = {$quotedFamilyCode} AND attribute_code NOT IN ({$flattenedAttributeCodes}) LIMIT 1000";

            $count = $this->executeWithPaging($sql);

            if ($count) {
                $output->writeln(
                    "<comment>{$count} values purged in family {$family->getCode()} with missing attributes</comment>"
                );
            } else {
                $output->writeln("<info>No values to purge for family {$family->getCode()}</info>");
            }
        }
    }

    /**
     * @param string $sql
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return int
     */
    protected function executeWithPaging($sql)
    {
        $count = 0;
        do {
            $stmt = $this->entityManager->getConnection()->executeQuery($sql);
            $stmt->execute();
            $lastCount = $stmt->rowCount();
            $count += $lastCount;
        } while ($lastCount > 0);

        return $count;
    }

    /**
     * Quote a PHP array to allow using it in native SQL query
     *
     * @param array $array
     *
     * @return string
     */
    protected function quoteArray(array $array)
    {
        array_walk(
            $array,
            function (&$value) {
                $value = $this->entityManager->getConnection()->quote($value);
            }
        );

        return implode(', ', $array);
    }
}
