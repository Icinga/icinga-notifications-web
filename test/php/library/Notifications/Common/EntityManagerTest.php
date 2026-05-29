<?php

namespace Tests\Icinga\Module\Notifications\Common;

use DateTime;
use DateTimeInterface;
use Exception;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Sql\Sql;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

// phpcs:ignoreFile PSR1.Classes.ClassDeclaration.MultipleClasses
class Gadget extends Model
{
    public function getTableName()
    {
        return 'gadget';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['workshop_id', 'name'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('workshop', Workshop::class);

        $relations->belongsToMany('stickers', Sticker::class)
            ->through('gadget_sticker');
    }
}

class Sticker extends Model
{
    public function getTableName()
    {
        return 'sticker';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['label'];
    }
}

class Workshop extends Model
{
    public function getTableName()
    {
        return 'workshop';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['name'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('gadgets', Gadget::class);
    }
}

class Flag extends Model
{
    public function getTableName()
    {
        return 'flag';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['label', 'enabled'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new BoolCast(['enabled']));
    }
}

/**
 * Test double for {@see EntityManager} that returns deterministic, monotonically increasing
 * timestamps from {@see now()}, so `changed_at`-stamping assertions can use exact values.
 */
class TickingEntityManager extends EntityManager
{
    /** @var int Seconds since the epoch returned by the next call to {@see now()}. Reset per test. */
    public static int $tick = 0;

    protected function now(): DateTime
    {
        return new DateTime('@' . ++self::$tick);
    }
}

class Stamped extends Model
{
    public function getTableName()
    {
        return 'stamped';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['name', 'changed_at'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
    }
}

class Trinket extends Model
{
    public function getTableName()
    {
        return 'trinket';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['name'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('charms', Charm::class);
    }
}

class Charm extends Model
{
    public function getTableName()
    {
        return 'charm';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['trinket_id', 'label'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['trinket_id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('trinket', Trinket::class);
    }
}

class Pairing extends Model
{
    public function getTableName()
    {
        return 'pairing';
    }

    public function getKeyName(): array
    {
        return ['left_id', 'right_id'];
    }

    public function getColumns()
    {
        return ['left_id', 'right_id', 'label'];
    }
}

/**
 * Test double for {@see Connection} that records every write call and forwards to the real parent.
 *
 * Lets tests assert that the EntityManager issues the *exact* set of writes expected — and skips
 * the ones it shouldn't — without giving up the real sqlite-backed end-to-end execution.
 */
class RecordingConnection extends Connection
{
    /**
     * Each entry is one recorded write keyed by `method` ('insert'|'update'|'delete'), plus `table`,
     * `data` (for insert/update), and `condition` (for update/delete).
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    public function insert(string $table, iterable $data): PDOStatement
    {
        $data = is_array($data) ? $data : iterator_to_array($data);
        $this->calls[] = ['method' => 'insert', 'table' => $table, 'data' => $data];

        return parent::insert($table, $data);
    }

    public function update(
        string|array $table,
        iterable $data,
        string|array|null $condition = null,
        string $operator = Sql::ALL
    ): PDOStatement {
        $data = is_array($data) ? $data : iterator_to_array($data);
        $this->calls[] = [
            'method'    => 'update',
            'table'     => $table,
            'data'      => $data,
            'condition' => $condition,
        ];

        return parent::update($table, $data, $condition, $operator);
    }

    public function delete(
        string|array $table,
        string|array|null $condition = null,
        string $operator = Sql::ALL
    ): PDOStatement {
        $this->calls[] = ['method' => 'delete', 'table' => $table, 'condition' => $condition];

        return parent::delete($table, $condition, $operator);
    }

    /**
     * Drop the recorded calls so subsequent assertions only see writes from the next action
     *
     * @return void
     */
    public function resetCalls(): void
    {
        $this->calls = [];
    }
}

class EntityManagerTest extends TestCase
{
    /** @var RecordingConnection */
    protected $db;

    protected function setUp(): void
    {
        $db = new RecordingConnection(['db' => 'sqlite', 'dbname' => ':memory:']);
        $db->exec(
            'CREATE TABLE workshop (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget (id INTEGER PRIMARY KEY AUTOINCREMENT, workshop_id INTEGER, name VARCHAR NOT NULL);'
            . 'CREATE TABLE sticker (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_sticker (gadget_id INTEGER NOT NULL, sticker_id INTEGER NOT NULL);'
            . 'CREATE TABLE flag (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL, enabled VARCHAR NOT NULL);'
            . 'CREATE TABLE stamped (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL, changed_at INTEGER);'
            . 'CREATE TABLE trinket (id BLOB PRIMARY KEY, name VARCHAR NOT NULL);'
            . 'CREATE TABLE charm (id INTEGER PRIMARY KEY AUTOINCREMENT, trinket_id BLOB NOT NULL, label VARCHAR NOT NULL);'
            . 'CREATE TABLE pairing ('
            . 'left_id INTEGER NOT NULL, right_id INTEGER NOT NULL, label VARCHAR NOT NULL,'
            . ' PRIMARY KEY (left_id, right_id));'
        );

        $this->db = $db;

        TickingEntityManager::$tick = 0;
    }

    protected function em(): EntityManager
    {
        return new TickingEntityManager($this->db);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $sql): array
    {
        return $this->db->prepexec($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testInsertAssignsGeneratedKeyAndMarksNotNew()
    {
        $workshop = new Workshop();
        $this->assertTrue($workshop->isNew());
        $workshop->name = 'Acme';

        $this->em()->save($workshop);

        $this->assertFalse($workshop->isNew(), 'A saved model is no longer new');
        $this->assertSame(1, $workshop->id, 'The generated primary key is written back to the model');
        $this->assertSame([['id' => 1, 'name' => 'Acme']], $this->rows('SELECT * FROM workshop'));
    }

    public function testHydratedModelIsLoadedAndClean()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $loaded = Workshop::on($this->db)->first();

        $this->assertNotNull($loaded);
        $this->assertFalse($loaded->isNew(), 'A hydrated model is not new');
        $this->assertFalse($loaded->isDirty(), 'A freshly hydrated model has no changes');
    }

    public function testUpdateWritesOnlyChangedColumns()
    {
        $gadget = new Gadget();
        $gadget->workshop_id = 5;
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);

        $gadget->name = 'Wrench';
        $this->assertSame(['name' => true], $gadget->getDirtyMap(), 'Only the changed column is tracked as dirty');

        $this->em()->save($gadget);

        $this->assertFalse($gadget->isDirty(), 'The model is clean after an update');
        $this->assertSame(
            [['workshop_id' => 5, 'name' => 'Wrench']],
            $this->rows('SELECT workshop_id, name FROM gadget'),
            'The unchanged column is preserved'
        );
    }

    public function testNoOpSaveOnCleanModelDoesNothing()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $this->em()->save($workshop);

        $this->assertSame([['id' => 1, 'name' => 'Acme']], $this->rows('SELECT * FROM workshop'));
    }

    public function testDeleteRemovesTheRow()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $this->em()->delete($workshop);

        $this->assertSame([], $this->rows('SELECT * FROM workshop'));
    }

    public function testDeleteIgnoresNewModel()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';

        $this->em()->delete($workshop);

        $this->assertSame([], $this->rows('SELECT * FROM workshop'));
    }

    public function testHasManyCascadeCopiesParentKeyIntoChildren()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';

        $spanner = new Gadget();
        $spanner->name = 'Spanner';
        $wrench = new Gadget();
        $wrench->name = 'Wrench';
        $workshop->gadgets = [$spanner, $wrench];

        $this->em()->save($workshop);

        $this->assertSame( $workshop->id,  $spanner->workshop_id);
        $this->assertSame($workshop->id,  $wrench->workshop_id);
        $this->assertSame(
            [
                ['name' => 'Spanner', 'workshop_id' =>  $workshop->id],
                ['name' => 'Wrench', 'workshop_id' =>  $workshop->id],
            ],
            $this->rows('SELECT name, workshop_id FROM gadget ORDER BY id')
        );
    }

    public function testBelongsToCascadeSavesParentFirst()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $gadget->workshop = $workshop;

        $this->em()->save($gadget);

        $this->assertFalse($workshop->isNew(), 'The parent was persisted');
        $this->assertSame(
             $workshop->id,
             $gadget->workshop_id,
            'The parent key was copied into the source foreign key'
        );
    }

    public function testManyToManyWritesJunctionRows()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];

        $this->em()->save($gadget);

        $this->assertFalse($sticker->isNew(), 'The target was persisted');
        $this->assertSame(
            [['gadget_id' =>  $gadget->id, 'sticker_id' =>  $sticker->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker')
        );
    }

    public function testGraphIsRolledBackOnFailure()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';

        // A gadget without a name violates the NOT NULL constraint and fails mid-cascade
        $workshop->gadgets = [new Gadget()];

        try {
            $this->em()->save($workshop);
            $this->fail('Expected the failing save to throw');
        } catch (Exception $e) {
            // expected
        }

        $this->assertSame([], $this->rows('SELECT * FROM workshop'), 'The parent insert was rolled back');
    }

    public function testPropertyBehaviorConvertsValuesOnPersist()
    {
        $flag = new Flag();
        $flag->label = 'shiny';
        $flag->enabled = true;

        $this->em()->save($flag);

        $this->assertSame(
            [['label' => 'shiny', 'enabled' => 'y']],
            $this->rows('SELECT label, enabled FROM flag'),
            'BoolCast converts true to its database value on insert'
        );

        $this->assertTrue($flag->enabled, 'The model value remains in PHP form after save');
    }

    public function testPropertyBehaviorConvertsValuesOnUpdate()
    {
        $flag = new Flag();
        $flag->label = 'shiny';
        $flag->enabled = true;
        $this->em()->save($flag);

        $flag->enabled = false;
        $this->em()->save($flag);

        $this->assertSame(
            [['enabled' => 'n']],
            $this->rows('SELECT enabled FROM flag'),
            'BoolCast converts the new value on update'
        );
    }

    public function testChangedAtIsStampedOnInsert()
    {
        $stamped = new Stamped();
        $stamped->name = 'Widget';
        $this->em()->save($stamped);

        $this->assertInstanceOf(
            DateTimeInterface::class,
            $stamped->changed_at,
            'The behavior set a DateTime on the model'
        );
        $this->assertSame(1, $stamped->changed_at->getTimestamp());
        $this->assertSame(
            [['name' => 'Widget', 'changed_at' => 1000]],
            $this->rows('SELECT name, changed_at FROM stamped'),
            'The DateTime is converted to a millisecond timestamp on the way to the database'
        );
    }

    public function testChangedAtIsStampedOnUpdate()
    {
        $stamped = new Stamped();
        $stamped->name = 'Widget';
        $this->em()->save($stamped); // changed_at -> 1s

        $stamped->name = 'Gizmo';
        $this->em()->save($stamped); // behavior re-stamps -> 2s

        $this->assertSame(2, $stamped->changed_at->getTimestamp());
        $this->assertSame(
            [['name' => 'Gizmo', 'changed_at' => 2000]],
            $this->rows('SELECT name, changed_at FROM stamped'),
            'The behavior runs on UPDATE and its column is included in the SET'
        );
    }

    public function testChangedAtIsNotStampedIfRowIsUnchanged()
    {
        $stamped = new Stamped();
        $stamped->name = 'Widget';
        $this->em()->save($stamped); // changed_at -> 1s
        $this->em()->save($stamped);
        $this->assertSame(1, $stamped->changed_at->getTimestamp());
    }

    public function testChangedAtIsNotChangedIfRowHasNoNewChanges()
    {
        $stamped = new Stamped();
        $stamped->name = 'Widget';
        $this->em()->save($stamped); // changed_at -> 1s
        $this->assertSame(1, $stamped->changed_at->getTimestamp());
        $stamped->name = 'Widget';
        $this->em()->save($stamped);
        $this->assertSame(1, $stamped->changed_at->getTimestamp());
        $this->assertCount(1, $this->rows('SELECT * FROM stamped'));
    }
    public function testBinaryKeyRoundTripsOnInsert()
    {
        $id = hex2bin('deadbeefcafebabe1234567890abcdef');

        $trinket = new Trinket();
        $trinket->id = $id;
        $trinket->name = 'Amulet';

        $this->em()->save($trinket);

        $this->assertSame($id, $trinket->id, 'The binary key is unchanged on the model after save');
        $this->assertSame(
            [['id' => $id, 'name' => 'Amulet']],
            $this->rows('SELECT id, name FROM trinket'),
            'The binary key was stored byte-for-byte'
        );
    }

    public function testBinaryKeyIsUsedInUpdateWhere()
    {
        $id = hex2bin('deadbeefcafebabe1234567890abcdef');

        $trinket = new Trinket();
        $trinket->id = $id;
        $trinket->name = 'Amulet';
        $this->em()->save($trinket);

        $trinket->name = 'Charm';
        $this->em()->save($trinket);

        $this->assertSame(
            [['id' => $id, 'name' => 'Charm']],
            $this->rows('SELECT id, name FROM trinket'),
            'The UPDATE matched the row by its binary primary key'
        );
    }

    public function testBinaryKeyIsUsedInDeleteWhere()
    {
        $id = hex2bin('deadbeefcafebabe1234567890abcdef');

        $trinket = new Trinket();
        $trinket->id = $id;
        $trinket->name = 'Amulet';
        $this->em()->save($trinket);

        $this->em()->delete($trinket);

        $this->assertSame([], $this->rows('SELECT id FROM trinket'));
    }

    public function testReadingALazyRelationOnLoadedModelDoesNotMarkItDirty()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $loaded = null;
        foreach (Workshop::on($this->db)->execute() as $row) {
            $loaded = $row;
            break;
        }

        // Without the re-entrance guard in setProperty this would recurse forever, because
        // PropertiesWithDefaults::getProperty() memoizes the resolved Closure via setProperty().
        $relation = $loaded->gadgets;

        $this->assertNotNull($relation);
        $this->assertFalse(
            $loaded->isDirty(),
            'Reading a lazily-loaded relation must not mark the model dirty'
        );
    }

    public function testAssigningALazyRelationOnLoadedModelMarksItDirtyWithoutResolvingIt()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $loaded = null;
        foreach (Workshop::on($this->db)->execute() as $row) {
            $loaded = $row;
            break;
        }

        // Replace the (still-Closure) relation without first triggering its loader.
        $loaded->gadgets = [new Gadget()];

        $this->assertArrayHasKey(
            'gadgets',
            $loaded->getDirtyMap(),
            'Reassigning a relation marks it dirty so saveGraph cascades the new value'
        );
    }

    public function testBinaryParentKeyIsCopiedIntoChildOnCascade()
    {
        $id = hex2bin('deadbeefcafebabe1234567890abcdef');

        $trinket = new Trinket();
        $trinket->id = $id;
        $trinket->name = 'Amulet';

        $charm = new Charm();
        $charm->label = 'rune';
        $trinket->charms = [$charm];

        $this->em()->save($trinket);

        $this->assertSame($id, $charm->trinket_id, 'The parent binary key was copied into the child');
        $this->assertSame(
            [['trinket_id' => $id, 'label' => 'rune']],
            $this->rows('SELECT trinket_id, label FROM charm'),
            'The child row carries the parent binary key'
        );
    }

    public function testSaveAfterDeleteReInserts()
    {
        $w = new Workshop();
        $w->name = 'Acme';
        $this->em()->save($w);
        $this->em()->delete($w);

        $this->assertTrue($w->isNew(), 'Deleted model is treated as new again');
        $this->assertFalse($w->isDirty(), 'Dirty state was cleared');

        $w->name = 'Acme 2';
        $this->em()->save($w);
        $this->assertSame([['name' => 'Acme 2']], $this->rows('SELECT name FROM workshop'));
    }

    public function testCompoundKeyUpdateMatchesByAllKeyColumns()
    {
        // Two rows sharing left_id but differing in right_id ensure the UPDATE's WHERE has to
        // include both key columns to target only one of them.
        $a = new Pairing();
        $a->left_id = 1;
        $a->right_id = 1;
        $a->label = 'A';
        $this->em()->save($a);

        $b = new Pairing();
        $b->left_id = 1;
        $b->right_id = 2;
        $b->label = 'B';
        $this->em()->save($b);

        $b->label = 'B2';
        $this->em()->save($b);

        $this->assertSame(
            [
                ['left_id' => 1, 'right_id' => 1, 'label' => 'A'],
                ['left_id' => 1, 'right_id' => 2, 'label' => 'B2'],
            ],
            $this->rows('SELECT left_id, right_id, label FROM pairing ORDER BY right_id'),
            'Only the row matching all key columns was updated'
        );
    }

    public function testCompoundKeyDeleteMatchesByAllKeyColumns()
    {
        $a = new Pairing();
        $a->left_id = 1;
        $a->right_id = 1;
        $a->label = 'A';
        $this->em()->save($a);

        $b = new Pairing();
        $b->left_id = 1;
        $b->right_id = 2;
        $b->label = 'B';
        $this->em()->save($b);

        $this->em()->delete($b);

        $this->assertSame(
            [['left_id' => 1, 'right_id' => 1, 'label' => 'A']],
            $this->rows('SELECT left_id, right_id, label FROM pairing'),
            'Only the row matching all key columns was deleted'
        );
    }

    public function testCompoundKeyInsertWritesBothKeyColumnsAndMarksClean()
    {
        $p = new Pairing();
        $p->left_id = 7;
        $p->right_id = 9;
        $p->label = 'A';

        $this->em()->save($p);

        $this->assertFalse($p->isNew(), 'A saved compound-key model is no longer new');
        $this->assertFalse($p->isDirty());
        $this->assertSame(7, $p->left_id, 'left_id was not overwritten by a lastInsertId fetch');
        $this->assertSame(9, $p->right_id, 'right_id was not overwritten by a lastInsertId fetch');
        $this->assertSame(
            [['left_id' => 7, 'right_id' => 9, 'label' => 'A']],
            $this->rows('SELECT left_id, right_id, label FROM pairing')
        );
    }

    public function testReSavingACleanModelIssuesNoWrites()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $this->db->resetCalls();
        $this->em()->save($workshop);

        $this->assertSame([], $this->db->calls, 'A second save of an unchanged model issues no writes');
    }

    public function testInsertOfNewModelIssuesExactlyOneInsertAndNoOtherWrites()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';

        $this->em()->save($workshop);

        $this->assertSame(
            [['method' => 'insert', 'table' => 'workshop', 'data' => ['name' => 'Acme']]],
            $this->db->calls,
            'Inserting a new model emits exactly one INSERT for that row'
        );
    }

    public function testUpdateWritesOnlyTheChangedColumnInTheSetClause()
    {
        $gadget = new Gadget();
        $gadget->workshop_id = 5;
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);

        $this->db->resetCalls();
        $gadget->name = 'Wrench';
        $this->em()->save($gadget);

        $this->assertCount(1, $this->db->calls, 'Exactly one write is issued for the update');
        $this->assertSame('update', $this->db->calls[0]['method']);
        $this->assertSame('gadget', $this->db->calls[0]['table']);
        $this->assertSame(
            ['name' => 'Wrench'],
            $this->db->calls[0]['data'],
            'Unchanged columns are not part of the SET clause'
        );
        $this->assertSame(
            ['id = ?' => $gadget->id],
            $this->db->calls[0]['condition'],
            'The WHERE matches the row by its primary key'
        );
    }

    public function testCompoundKeyUpdateScopesByAllKeyColumns()
    {
        $p = new Pairing();
        $p->left_id = 1;
        $p->right_id = 2;
        $p->label = 'A';
        $this->em()->save($p);

        $this->db->resetCalls();
        $p->label = 'B';
        $this->em()->save($p);

        $this->assertCount(1, $this->db->calls);
        $this->assertSame(
            ['left_id = ?' => 1, 'right_id = ?' => 2],
            $this->db->calls[0]['condition'],
            'Both key columns are in the WHERE'
        );
    }

    public function testDeleteIssuesExactlyOneDeleteAndNoOtherWrites()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);
        $id = $workshop->id;

        $this->db->resetCalls();
        $this->em()->delete($workshop);

        $this->assertSame(
            [['method' => 'delete', 'table' => 'workshop', 'condition' => ['id = ?' => $id]]],
            $this->db->calls,
            'Exactly one DELETE is issued, scoped by primary key — no incidental UPDATE first'
        );
    }

    public function testCascadeInsertEmitsOneInsertPerRowAndNoUpdates()
    {
        $spanner = new Gadget();
        $spanner->name = 'Spanner';
        $wrench = new Gadget();
        $wrench->name = 'Wrench';

        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $workshop->gadgets = [$spanner, $wrench];

        $this->em()->save($workshop);

        $methods = array_column($this->db->calls, 'method');
        $tables  = array_column($this->db->calls, 'table');

        $this->assertSame(['insert', 'insert', 'insert'], $methods, 'Three inserts: parent + two children');
        $this->assertSame(['workshop', 'gadget', 'gadget'], $tables, 'Parent is inserted before children');
    }

    public function testManyToManyEmitsOneInsertPerEnd()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];

        $this->em()->save($gadget);

        $this->assertSame(
            ['gadget', 'sticker', 'gadget_sticker'],
            array_column($this->db->calls, 'table'),
            'Gadget, sticker, and one junction row — three inserts in this order'
        );
        $this->assertSame(['insert', 'insert', 'insert'], array_column($this->db->calls, 'method'));
    }

    public function testUpdatingOnlyAChildDoesNotReWriteTheParent()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $workshop->gadgets = [$gadget];
        $this->em()->save($workshop);

        $this->db->resetCalls();
        $gadget->name = 'Wrench';
        $this->em()->save($gadget);

        $this->assertCount(1, $this->db->calls, 'Only the child is written');
        $this->assertSame('update', $this->db->calls[0]['method']);
        $this->assertSame('gadget', $this->db->calls[0]['table']);
    }

    public function testNoWritesIfRelationIsReassignedButOwnColumnsAreUnchanged()
    {
        // Loaded parent with no column changes; we only swap its many-to-many targets. The parent
        // row's own data is identical, so persist() should be a no-op — no UPDATE on the parent.
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);

        $loaded = null;
        foreach (Gadget::on($this->db)->execute() as $row) {
            $loaded = $row;
            break;
        }
        $this->assertInstanceOf(Gadget::class, $loaded);

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $loaded->stickers = [$sticker];

        $this->db->resetCalls();
        $this->em()->save($loaded);

        $tables = array_column($this->db->calls, 'table');
        $this->assertNotContains(
            'gadget',
            $tables,
            'The parent gadget row was not modified, so it must not be re-written'
        );
    }
}
