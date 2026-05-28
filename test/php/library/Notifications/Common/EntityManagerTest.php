<?php

namespace Tests\Icinga\Module\Notifications\Common;

use DateTime;
use DateTimeInterface;
use Exception;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Common\Model;
use Icinga\Module\Notifications\Model\Behavior\ChangedAt;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use PDO;
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
// * Test double for {@see ChangedAt} that returns deterministic, monotonically increasing timestamps
 */
class FixedChangedAt extends ChangedAt
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
        $behaviors->add(new FixedChangedAt());
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

class EntityManagerTest extends TestCase
{
    /** @var Connection */
    protected $db;

    protected function setUp(): void
    {
        $db = new Connection(['db' => 'sqlite', 'dbname' => ':memory:']);
        $db->exec(
            'CREATE TABLE workshop (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget (id INTEGER PRIMARY KEY AUTOINCREMENT, workshop_id INTEGER, name VARCHAR NOT NULL);'
            . 'CREATE TABLE sticker (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_sticker (gadget_id INTEGER NOT NULL, sticker_id INTEGER NOT NULL);'
            . 'CREATE TABLE flag (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL, enabled VARCHAR NOT NULL);'
            . 'CREATE TABLE stamped (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL, changed_at INTEGER);'
            . 'CREATE TABLE trinket (id BLOB PRIMARY KEY, name VARCHAR NOT NULL);'
            . 'CREATE TABLE charm (id INTEGER PRIMARY KEY AUTOINCREMENT, trinket_id BLOB NOT NULL, label VARCHAR NOT NULL);'
        );

        $this->db = $db;

        FixedChangedAt::$tick = 0;
    }

    protected function em(): EntityManager
    {
        return new EntityManager($this->db);
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
        $this->assertSame(1, (int) $workshop->id, 'The generated primary key is written back to the model');
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
        $this->assertSame(['name'], $gadget->getDirty(), 'Only the changed column is tracked as dirty');

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

        $this->assertSame((int) $workshop->id, (int) $spanner->workshop_id);
        $this->assertSame((int) $workshop->id, (int) $wrench->workshop_id);
        $this->assertSame(
            [
                ['name' => 'Spanner', 'workshop_id' => (int) $workshop->id],
                ['name' => 'Wrench', 'workshop_id' => (int) $workshop->id],
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
            (int) $workshop->id,
            (int) $gadget->workshop_id,
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
            [['gadget_id' => (int) $gadget->id, 'sticker_id' => (int) $sticker->id]],
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

        $this->assertContains(
            'gadgets',
            $loaded->getDirty(),
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
}
