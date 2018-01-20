<?php

namespace AppBundle\Entity;

use AppBundle\Enum\FriendTypes;
use AppBundle\Enum\MeetingTypes;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NoResultException;
use AppBundle\Entity\Connection;

/**
 * Class ConnectionRepository
 * @package AppBundle\Entity
 */
class ConnectionRepository extends EntityRepository
{

    /**
     * @param String $city
     * @param String $year
     * @param String $type
     * @return array
     */
    public function getMatches($city, $year, $type)
    {
        $query = "
            SELECT c.created_at
            FROM connection c
            WHERE YEAR(c.created_at) = :year";

        $params['year'] = $year;

        if ($city !== "") {
            $query .= " AND c.city_id = :city";
            $params['city'] = $city;
        }

        if ($type !== "") {
            $query .= " AND c.type = :type";
            $params['type'] = $type;
        }

        $query .= " ORDER BY c.created_at ASC";

        $stmt = $this->getEntityManager()->getConnection()->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array
     */
    public function getYearSpan()
    {
        // get the years in a unique list
        $query = "
            SELECT DISTINCT(SUBSTRING(c.created_at, 1, 4)) as year
            FROM connection c
            ORDER BY year";
        $stmt = $this->getEntityManager()->getConnection()->prepare($query);
        $stmt->execute();

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'year');
    }

    /**
     * @param Connection $connection
     * @return Connection
     */
    public function save(Connection $connection)
    {
        $this->getEntityManager()->persist($connection);
        $this->getEntityManager()->flush();

        return $connection;
    }

    /**
     * @param Connection $connection
     */
    public function remove(Connection $connection)
    {
        $this->getEntityManager()->remove($connection);
        $this->getEntityManager()->flush();
    }

    /**
     * @return \Doctrine\ORM\Query
     */
    public function getFindAllQueryBuilderForUser($searchString, User $user)
    {
        $cities = $this->getEntityManager()->getRepository('AppBundle\Entity\City')
            ->createQueryBuilder('c')
            ->innerJoin('c.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $user->getId())
            ->getQuery()
            ->execute();

        $cityIds = [];
        foreach ($cities as $city) {
            $cityIds[] = $city->getId();
        }

        $qb = $this
            ->createQueryBuilder('c')
            ->select('c, f, l, cb, c2, u')
            ->innerJoin('c.fluentSpeaker', 'f')
            ->innerJoin('c.learner', 'l')
            ->innerJoin('c.createdBy', 'cb')
            ->innerJoin('c.city', 'city')
            ->leftJoin('c.comments', 'c2')
            ->leftJoin('c2.user', 'u')
            ->andWhere('city.id IN (:cityIds)')
            ->setParameter('cityIds', $cityIds)
        ;

        if ($searchString) {
            $qb
                ->andwhere("
                    f.email LIKE :searchString
                    OR l.email LIKE :searchString
                    OR CONCAT(CONCAT(f.firstName, ' '), f.lastName) LIKE :searchString
                    OR CONCAT(CONCAT(l.firstName, ' '), l.lastName) LIKE :searchString
                ")
                ->setParameter('searchString', '%'.trim($searchString).'%')
            ;
        }

        return $qb
            ->orderBy('c.id', 'desc')
        ;
    }

    /**
     * @param User $user1
     * @param User $user2
     * @return Connection[]
     */
    public function findForUsers(User $user1, User $user2)
    {
        return $this
            ->createQueryBuilder('c')
            ->where('c.fluentSpeaker = :user1 and c.learner = :user2')
            ->orWhere('c.fluentSpeaker = :user2 and c.learner = :user1')
            ->setParameters([
                'user1' => $user1,
                'user2' => $user2,
            ])
            ->getQuery()
            ->execute()
            ;
    }

    /**
     * @param User $user
     * @return int
     */
    public function isUserConnectionExists(User $user1, User $user2)
    {
        $qb = $this->createQueryBuilder('c');

        $qb
            ->select('COUNT(c.id)')
            ->where('c.fluentSpeaker = :user1 and c.learner = :user2')
            ->orWhere('c.fluentSpeaker = :user2 and c.learner = :user1')
            ->setParameters([
                'user1' => $user1,
                'user2' => $user2,
            ])
        ;

        return $qb->getQuery()->getSingleScalarResult()? true: false;
    }

    /**
     * @param Municipality $municipality
     *
     * @return Connection
     */
    public function findStartFriendsByMunicipality(Municipality $municipality)
    {
        return $this
            ->createQueryBuilder('c')
            ->where('c.type = :type')
            ->andWhere('c.municipality = :municipality')
            ->setParameter('type', FriendTypes::START)
            ->setParameter('municipality', $municipality)
            ->getQuery()
            ->execute()
            ;
    }

    /**
     * @return Connection[]
     */
    public function findForMeetingFollowUp($createdAt, $previousMailsCount)
    {
        $from = clone $createdAt;
        $from->setTime(0, 0, 0);
        $to = clone $from;
        $to->setTime(23, 59, 59);

        return $this
            ->createQueryBuilder('c')
            ->where('c.type != :type')
            ->andWhere('
                c.fluentSpeakerMeetingStatus = :statusUnknown or c.fluentSpeakerMeetingStatus = :statusNotYetMet
                or c.learnerMeetingStatus = :statusUnknown or c.learnerMeetingStatus = :statusNotYetMet
            ')
            ->andWhere('c.createdAt between :from and :to')
            ->andWhere('
                c.fluentSpeakerMeetingStatusEmailsCount = :previousMailsCount
                or c.learnerMeetingStatusEmailsCount = :previousMailsCount
            ')
            ->setParameter('type', FriendTypes::START)
            ->setParameter('statusUnknown', MeetingTypes::UNKNOWN)
            ->setParameter('statusNotYetMet', MeetingTypes::NOT_YET_MET)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('previousMailsCount', $previousMailsCount)
            ->getQuery()
            ->execute()
            ;

    }
}
