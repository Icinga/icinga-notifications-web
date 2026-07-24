<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use DateTime;
use DateTimeInterface;
use Exception;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Common\Model;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Badge;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Charm;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Flag;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Gadget;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\GadgetBadge;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\GadgetTag;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Pairing;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\RecordingConnection;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Stamped;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\StampedNote;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Sticker;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Tag;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\TickingEntityManager;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Trinket;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Workshop;
use ipl\Stdlib\Filter;

/**
 * End-to-end tests for the {@see EntityManager} write path.
 *
 * Each test runs against a fresh in-memory sqlite schema (see {@see self::setUp()}) through a
 * {@see RecordingConnection}, so an assertion can inspect both the resulting rows and the exact set of
 * writes issued. The EntityManager is a {@see TickingEntityManager}, whose clock returns 1s, 2s, 3s, …
 * on successive `changed_at` stamps, so those stamps can be asserted by value.
 *
 * The fixtures model the relation shapes the real schema uses: HasMany ({@see Workshop}->gadget),
 * BelongsTo ({@see Gadget}->workshop), a plain many-to-many ({@see Gadget}->sticker) and a soft-delete
 * many-to-many through a junction model ({@see Gadget}->tag via {@see GadgetTag}), plus models exercising
 * compound keys ({@see Pairing}), binary keys ({@see Trinket}/{@see Charm}) and column behaviors
 * ({@see Flag}, {@see Stamped}).
 */
class EntityManagerTest extends TestCase
{
    /** @var RecordingConnection The sqlite-backed connection recording every write of the test */
    protected $db;

    /**
     * Create the in-memory schema and reset the deterministic clock before each test
     */
    protected function setUp(): void
    {
        $this->db = $this->connect();
        TickingEntityManager::$tick = 0;
    }

    /**
     * Open a fresh in-memory sqlite connection and create the fixture schema on it
     *
     * @param array<int, mixed> $pdoOptions Extra PDO options, e.g. to reproduce driver quirks
     *
     * @return RecordingConnection
     */
    protected function connect(array $pdoOptions = []): RecordingConnection
    {
        $config = ['db' => 'sqlite', 'dbname' => ':memory:'];
        if ($pdoOptions) {
            $config['options'] = $pdoOptions;
        }

        $db = new RecordingConnection($config);
        $db->exec(
            'CREATE TABLE workshop (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget (id INTEGER PRIMARY KEY AUTOINCREMENT, workshop_id INTEGER, name VARCHAR NOT NULL);'
            . 'CREATE TABLE sticker (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_sticker (gadget_id INTEGER NOT NULL, sticker_id INTEGER NOT NULL);'
            . 'CREATE TABLE flag (id INTEGER PRIMARY KEY AUTOINCREMENT,
                label VARCHAR NOT NULL, enabled VARCHAR NOT NULL);'
            . 'CREATE TABLE stamped (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL, changed_at INTEGER);'
            . 'CREATE TABLE stamped_note ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, stamped_id INTEGER NOT NULL, text VARCHAR NOT NULL,'
            . " changed_at INTEGER, deleted VARCHAR NOT NULL DEFAULT 'n');"
            . 'CREATE TABLE trinket (id BLOB PRIMARY KEY, name VARCHAR NOT NULL);'
            . 'CREATE TABLE charm (id INTEGER PRIMARY KEY AUTOINCREMENT,
                trinket_id BLOB NOT NULL, label VARCHAR NOT NULL);'
            . 'CREATE TABLE "values" ('
            . '"order" INTEGER NOT NULL, "group" INTEGER NOT NULL, "insert" VARCHAR NOT NULL,'
            . ' PRIMARY KEY ("order", "group"));'
            . 'CREATE TABLE tag (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_tag ('
            . 'gadget_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,'
            . " changed_at INTEGER NOT NULL, deleted VARCHAR NOT NULL DEFAULT 'n',"
            . ' PRIMARY KEY (gadget_id, tag_id));'
            . 'CREATE TABLE badge (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_badge ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' gadget_id INTEGER NOT NULL, badge_id INTEGER NOT NULL,'
            . " changed_at INTEGER NOT NULL, deleted VARCHAR NOT NULL DEFAULT 'n');"
        );

        return $db;
    }

    /**
     * Get a fresh EntityManager bound to the test connection, with a deterministic clock
     *
     * @return EntityManager
     */
    protected function em(): EntityManager
    {
        return new TickingEntityManager($this->db);
    }

    /**
     * Run a SELECT and return its rows as plain associative arrays
     *
     * @param string $sql
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $sql): array
    {
        return $this->db->prepexec($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the recorded writes (insert/update/delete) that targeted the given table
     *
     * Handy for asserting that the EntityManager left a table completely untouched.
     *
     * @param string $table
     *
     * @return list<array<string, mixed>>
     */
    protected function writesTo(string $table): array
    {
        return array_values(array_filter($this->db->calls, fn (array $c): bool => $c['table'] === $table));
    }

    /**
     * Build and persist a workshop with the given name
     *
     * @param string $name
     *
     * @return Workshop
     */
    protected function savedWorkshop(string $name = 'Acme'): Workshop
    {
        $workshop = (new Workshop())->setNew();
        $workshop->name = $name;
        $this->em()->save($workshop);

        return $workshop;
    }

    // -----------------------------------------------------------------------------------------------------
    // Single-row lifecycle: insert, update, no-op, delete
    // -----------------------------------------------------------------------------------------------------

    public function testInsertAssignsGeneratedKeyMarksModelLoadedAndEmitsExactlyOneInsert()
    {
        $workshop = (new Workshop())->setNew();
        $this->assertTrue(
            $workshop->isNew(),
            'A model flagged with setNew() should report as new before its first save'
        );
        $workshop->name = 'Acme';

        $this->em()->save($workshop);

        $this->assertFalse($workshop->isNew(), 'A saved model should no longer be new');
        $this->assertFalse($workshop->isModified(), 'A saved model should carry no pending changes');
        $this->assertSame(1, $workshop->id, 'The generated primary key should be written back to the model');
        $this->assertSame(
            [['method' => 'insert', 'table' => 'workshop', 'data' => ['name' => 'Acme']]],
            $this->db->calls,
            'Inserting a new model should emit exactly one INSERT for that row and nothing else'
        );
        $this->assertSame([['id' => 1, 'name' => 'Acme']], $this->rows('SELECT * FROM workshop'));
    }

    public function testUpdateWritesOnlyTheChangedColumnScopedByPrimaryKey()
    {
        $gadget = (new Gadget())->setNew();
        $gadget->workshop_id = 5;
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);

        $this->db->resetCalls();
        $gadget->name = 'Wrench';
        $this->assertSame(
            ['name' => true],
            $gadget->getModifiedProperties(),
            'Only the changed column should be tracked as modified'
        );

        $this->em()->save($gadget);

        $this->assertFalse($gadget->isModified(), 'The model should be unmodified after an update');
        $this->assertSame(
            [[
                'method'    => 'update',
                'table'     => 'gadget',
                'data'      => ['name' => 'Wrench'],
                'condition' => ['id = ?' => $gadget->id],
            ]],
            $this->db->calls,
            'The update should touch only the changed column and match the row by its primary key'
        );
        $this->assertSame(
            [['workshop_id' => 5, 'name' => 'Wrench']],
            $this->rows('SELECT workshop_id, name FROM gadget'),
            'The unchanged column should be preserved'
        );
    }

    public function testResavingAnUnchangedModelWhetherHydratedOrNotIssuesNoWrites()
    {
        $this->savedWorkshop('Acme');

        $loaded = Workshop::on($this->db)->first()->setNew(false);
        $this->assertNotNull($loaded);
        $this->assertFalse($loaded->isNew(), 'A hydrated model should not be new');
        $this->assertFalse($loaded->isModified(), 'A freshly hydrated model should have no changes');

        $this->db->resetCalls();
        $this->em()->save($loaded);
        $this->assertSame([], $this->db->calls, 'Saving an unchanged hydrated model should issue no writes');
    }

    public function testHardDeleteRemovesTheRowClearsTheKeyAndAllowsAFreshReinsert()
    {
        $workshop = $this->savedWorkshop('Acme');
        $oldId = $workshop->id;

        // workshop has no `deleted` column, so this is a hard delete, not a soft delete.
        $this->db->resetCalls();
        $this->em()->save($workshop->delete());

        $this->assertSame(
            [['method' => 'delete', 'table' => 'workshop', 'condition' => ['id = ?' => $oldId]]],
            $this->db->calls,
            'A model without a deleted column should be removed with a single DELETE scoped by its primary key'
        );
        $this->assertSame([], $this->rows('SELECT * FROM workshop'), 'The row should be gone');
        $this->assertTrue($workshop->isNew(), 'A deleted model should be treated as new again');
        $this->assertFalse($workshop->isModified(), 'A deleted model should carry no pending changes');
        $this->assertFalse($workshop->hasProperty('id'), 'The auto-increment key should be cleared on delete');

        $workshop->name = 'Acme 2';
        $this->em()->save($workshop);
        $this->assertNotSame($oldId, $workshop->id, 'A save after delete should receive a fresh auto-increment id');
        $this->assertSame([['name' => 'Acme 2']], $this->rows('SELECT name FROM workshop'));
    }

    public function testSoftDeleteKeepsTheRowMarksItDeletedAndRestampsChangedAt()
    {
        // gadget_tag carries a `deleted` column, so delete() keeps the row and flips deleted to 'y'
        // rather than removing it, re-stamping changed_at in the process.
        $link = (new GadgetTag())->setNew();
        $link->gadget_id = 1;
        $link->tag_id = 1;
        $this->em()->save($link);                       // insert -> changed_at 1000

        $this->em()->save($link->delete());             // soft-deleted -> changed_at 2000

        $this->assertSame(
            [['gadget_id' => 1, 'tag_id' => 1, 'deleted' => 'y', 'changed_at' => 2000]],
            $this->rows('SELECT gadget_id, tag_id, deleted, changed_at FROM gadget_tag'),
            'A model with a deleted column should be kept, marked deleted = y and re-stamped, not removed'
        );
    }

    public function testDeletingANewModelThrowsAnException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model is marked as new and cannot be deleted');

        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $this->em()->save($workshop->delete());
    }

    public function testChangingMutabilityThrowsAnException()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model is marked for deletion');

        $link = (new GadgetTag())->setNew(false)->delete();
        $link->setNew();
    }

    // -----------------------------------------------------------------------------------------------------
    // Compound and binary primary keys
    // -----------------------------------------------------------------------------------------------------

    public function testCompoundKeyIsWrittenScopedAndClearedAcrossItsLifecycle()
    {
        // Two rows sharing `order` but differing in `group`: every WHERE has to include *both* key
        // columns to target exactly one of them. The Pairing fixture's table (`values`), key columns
        // (`order`/`group`) and plain column (`insert`) are all SQL reserved keywords, so this also
        // proves the EntityManager quotes table names, INSERT/UPDATE columns and the compound key WHERE.
        $a = (new Pairing())->setNew();
        $a->order = 1;
        $a->group = 1;
        $a->insert = 'A';
        $this->em()->save($a);

        $b = (new Pairing())->setNew();
        $b->order = 1;
        $b->group = 2;
        $b->insert = 'B';
        $this->em()->save($b);

        $this->assertFalse($b->isNew(), 'A saved compound-key model should no longer be new');
        $this->assertFalse($b->isModified(), 'A saved compound-key model should carry no pending changes');
        $this->assertSame(1, $b->order, 'order should not be overwritten by a lastInsertId fetch');
        $this->assertSame(2, $b->group, 'group should not be overwritten by a lastInsertId fetch');

        $this->db->resetCalls();
        $b->insert = 'B2';
        $this->em()->save($b);
        $this->assertSame(
            ['order = ?' => 1, 'group = ?' => 2],
            $this->db->calls[0]['condition'],
            'Both key columns should scope the UPDATE'
        );

        $this->em()->save($b->delete());
        $this->assertFalse($b->hasProperty('order'), 'Each part of a compound key should be cleared on delete');
        $this->assertFalse($b->hasProperty('group'), 'Each part of a compound key should be cleared on delete');
        $this->assertSame(
            [['order' => 1, 'group' => 1, 'insert' => 'A']],
            $this->rows('SELECT "order", "group", "insert" FROM "values"'),
            'Only the row matching all key columns should be updated then deleted; the sibling stays intact'
        );
    }

    public function testBinaryKeyRoundTripsThroughInsertUpdateDeleteAndCascade()
    {
        $id = hex2bin('deadbeefcafebabe1234567890abcdef');

        $trinket = (new Trinket())->setNew();
        $trinket->id = $id;
        $trinket->name = 'Amulet';

        $charm = (new Charm())->setNew();
        $charm->label = 'rune';
        $trinket->charm = Collection::create([$charm]);

        $this->em()->save($trinket);

        $this->assertSame($id, $trinket->id, 'The binary key should be unchanged on the model after insert');
        $this->assertSame($id, $charm->trinket_id, 'The parent binary key should be copied into the child');
        $this->assertSame(
            [['id' => $id, 'name' => 'Amulet']],
            $this->rows('SELECT id, name FROM trinket'),
            'The binary key should be stored byte-for-byte'
        );
        $this->assertSame(
            [['trinket_id' => $id, 'label' => 'rune']],
            $this->rows('SELECT trinket_id, label FROM charm'),
            'The child row should carry the parent binary key'
        );

        // Update and delete must both match the row by its binary primary key.
        $trinket->name = 'Charm';
        $this->em()->save($trinket);
        $this->assertSame(
            [['id' => $id, 'name' => 'Charm']],
            $this->rows('SELECT id, name FROM trinket'),
            'The UPDATE should match the row by its binary primary key'
        );

        $loaded = Trinket::on($this->db)->first()->setNew(false);
        $this->em()->save($loaded->delete());
        $this->assertSame(
            [],
            $this->rows('SELECT id FROM trinket'),
            'The DELETE should match the row by its binary key'
        );
        $this->assertFalse(
            $loaded->hasProperty('id'),
            'The application-assigned binary key should be cleared on delete'
        );
    }

    // -----------------------------------------------------------------------------------------------------
    // Cascading a graph in one save()
    // -----------------------------------------------------------------------------------------------------

    public function testSavingADeepGraphCascadesEveryRelationInOneCall()
    {
        // A single cascading save covering, in one go:
        //   - HasMany:              Workshop->gadget   (the parent key is copied into each child)
        //   - BelongsToMany (hard): Gadget->sticker    (linked through the plain gadget_sticker table)
        //   - BelongsToMany (soft): Gadget->tag        (linked through the GadgetTag junction model)
        // The sticker is shared between both gadgets, so its row is inserted once and linked twice.
        $spanner = (new Gadget())->setNew();
        $spanner->name = 'Spanner';
        $wrench = (new Gadget())->setNew();
        $wrench->name = 'Wrench';

        $shared = (new Sticker())->setNew();
        $shared->label = 'fragile';
        $sharp = (new Tag())->setNew();
        $sharp->name = 'sharp';

        $spanner->sticker = Collection::create([$shared]);
        $spanner->tag = Collection::create([$sharp]);
        $wrench->sticker = Collection::create([$shared]);

        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $workshop->gadget = Collection::create([$spanner, $wrench]);

        $this->em()->save($workshop);

        $this->assertSame($workshop->id, $spanner->workshop_id, 'The parent key should be copied into each child');
        $this->assertSame($workshop->id, $wrench->workshop_id, 'The parent key should be copied into each child');
        $this->assertFalse($shared->isNew(), 'A cascaded many-to-many target should be persisted');
        $this->assertFalse(
            $shared->hasProperty('gadget_id'),
            'A many-to-many target must not carry the junction foreign key as a stray property'
        );

        $this->assertSame('workshop', $this->db->calls[0]['table'], 'The parent should be inserted first');
        $this->assertSame(
            ['insert'],
            array_values(array_unique(array_column($this->db->calls, 'method'))),
            'A fresh graph should be persisted purely with inserts'
        );

        $this->assertCount(2, $this->writesTo('gadget'), 'Both children should be inserted');
        $this->assertCount(1, $this->writesTo('sticker'), 'The shared target should be inserted exactly once');
        $this->assertCount(2, $this->writesTo('gadget_sticker'), 'Each link should be its own junction insert');
        $this->assertCount(1, $this->writesTo('tag'), 'The tag target should be inserted');
        $this->assertCount(1, $this->writesTo('gadget_tag'), 'The soft-delete junction row should be inserted');

        $this->assertSame(
            [['gadget_id' => $spanner->id], ['gadget_id' => $wrench->id]],
            $this->rows('SELECT gadget_id FROM gadget_sticker ORDER BY gadget_id'),
            'The shared sticker should be linked to both gadgets'
        );
    }

    public function testBelongsToSavesTheParentFirstAndAssigningNullClearsTheForeignKey()
    {
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';

        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $gadget->workshop = $workshop;

        $this->em()->save($gadget);

        $this->assertFalse($workshop->isNew(), 'The parent should be persisted before the model that references it');
        $this->assertSame($workshop->id, $gadget->workshop_id, 'The parent key should be copied into the foreign key');

        // Load fresh and clear the relation by assigning null. The property then holds null (not a lazy-loader
        // closure), so saveGraph sees an explicit assignment and nulls the foreign key.
        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->workshop = null;
        $this->em()->save($loaded);

        $this->assertSame(
            [['name' => 'Spanner', 'workshop_id' => null]],
            $this->rows('SELECT name, workshop_id FROM gadget'),
            'Assigning null to a BelongsTo should null the foreign key on update'
        );
    }

    // -----------------------------------------------------------------------------------------------------
    // Many-to-many: the Collection API and its persistence semantics
    // -----------------------------------------------------------------------------------------------------

    public function testCollectionExposesTheStagedManyToManyMembership()
    {
        // Seed a gadget linked to two stickers, so reading the relation yields a lazily-loaded Collection.
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $fragile = (new Sticker())->setNew();
        $fragile->label = 'fragile';
        $heavy = (new Sticker())->setNew();
        $heavy->label = 'heavy';
        $gadget->sticker = Collection::create([$fragile, $heavy]);
        $this->em()->save($gadget);

        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $stickers = $loaded->sticker;
        $this->assertInstanceOf(Collection::class, $stickers, 'A to-many relation should read back as a Collection');
        $this->assertCount(2, $stickers, 'count() should reflect the two loaded members');
        $this->assertFalse($stickers->isReplacing(), 'A freshly loaded collection should be in merge mode');
        $this->assertFalse(
            $stickers->hasPendingChanges(),
            'A freshly loaded collection should have nothing to persist'
        );
        $this->assertFalse($loaded->isModified(), 'Reading a relation must not mark the model modified');

        foreach ($stickers as $sticker) {
            match ($sticker->label) {
                'fragile' => $fragile = $sticker,
                'heavy' => $heavy = $sticker
            };
        }

        $shiny = (new Sticker())->setNew();
        $shiny->label = 'shiny';
        $stickers->attach($shiny);
        $this->assertCount(3, $stickers, 'Attaching a new member should add it to the loaded base');
        $this->assertTrue($stickers->hasPendingChanges(), 'A staged attach should count as a pending change');

        $stickers->detach($fragile);
        $this->assertCount(2, $stickers, 'A detached member should drop out of the staged view');
        $this->assertContains(
            $fragile,
            $stickers->getDetachments(),
            'The detached member should be staged for removal'
        );
        $stickers->attach($fragile);
        $this->assertCount(3, $stickers, 'Re-attaching should restore the member');
        $this->assertSame([], $stickers->getDetachments(), 'Re-attaching a member should cancel its pending detach');

        $only = (new Sticker())->setNew();
        $only->label = 'only';
        $stickers->sync([$only]);
        $this->assertTrue($stickers->isReplacing(), 'sync() puts the collection into replace mode');
        $this->assertCount(1, $stickers, 'Replace mode should count exactly the synced members, ignoring the base');

        $stickers->clearPendingChanges();
        $this->assertFalse($stickers->hasPendingChanges(), 'clearPendingChanges() drops the staged writes');
        $this->assertFalse($stickers->isReplacing(), 'clearPendingChanges() leaves merge mode behind');

        $fresh = (new Workshop())->setNew();
        $this->assertFalse(isset($fresh->gadget), 'A non-hydrated model should have no collections by default');
    }

    public function testManyToManyPersistenceIsAdditiveAndDeduplicatesLinks()
    {
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $fragile = (new Sticker())->setNew();
        $fragile->label = 'fragile';
        $gadget->sticker = Collection::create([$fragile]);
        $this->em()->save($gadget);

        $this->assertFalse($fragile->isNew(), 'The target should be persisted');
        $this->assertSame(
            [['gadget_id' => $gadget->id, 'sticker_id' => $fragile->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'A many-to-many assignment should write the junction row'
        );

        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->sticker = Collection::create([$fragile]);
        $this->db->resetCalls();
        $this->em()->save($loaded);
        $this->assertSame(
            [],
            $this->writesTo('gadget_sticker'),
            'Re-saving an unchanged link must issue no junction write'
        );

        $heavy = (new Sticker())->setNew();
        $heavy->label = 'heavy';
        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->sticker = Collection::create([$heavy]);
        $this->em()->save($loaded);
        $this->assertSame(
            [['sticker_id' => $heavy->id]],
            $this->rows('SELECT sticker_id FROM gadget_sticker ORDER BY sticker_id'),
            'Overriding the default collection must reconcile the junction'
        );

        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->sticker = [];
        $this->db->resetCalls();
        $this->em()->save($loaded);
        $this->assertCount(
            1,
            $this->writesTo('gadget_sticker'),
            'A bare array assignment must reconcile the junction'
        );
        $this->assertCount(0, $this->rows('SELECT * FROM gadget_sticker'), 'Both links must be dropped');
    }

    public function testManyToManyReconciliationTreatsStringKeysAsEqualToIntKeys()
    {
        // Production drivers (MySQL/PgSQL) return keys as strings while persisted models hold them as ints,
        // so sync must treat those as the same link — re-saving an unchanged relation must stay a no-op,
        // not delete-and-reinsert. STRINGIFY_FETCHES reproduces that boundary; sqlite otherwise returns
        // ints on both sides and never exercises it.
        $db = $this->connect([PDO::ATTR_STRINGIFY_FETCHES => true]);

        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $sticker = (new Sticker())->setNew();
        $sticker->label = 'fragile';
        $gadget->sticker = Collection::create([$sticker]);
        (new TickingEntityManager($db))->save($gadget);

        $loaded = Gadget::on($db)->first()->setNew(false);
        $loaded->sticker = Collection::create([$sticker]);

        $db->resetCalls();
        (new TickingEntityManager($db))->save($loaded);

        $this->assertSame(
            [],
            array_filter($db->calls, fn ($call) => $call['table'] === 'gadget_sticker'),
            'An unchanged link must trigger no insert or delete even when keys come back as strings'
        );
        $this->assertSame(
            [['gadget_id' => '1', 'sticker_id' => '1']],
            $db->prepexec('SELECT gadget_id, sticker_id FROM gadget_sticker')->fetchAll(PDO::FETCH_ASSOC),
            'The single link should be left intact'
        );
    }

    public function testSoftDeleteJunctionDetachesRevivesAndLeavesUnchangedLinksUntouched()
    {
        // gadget->tag is linked through GadgetTag, which carries a `deleted` column: removals soft-delete
        // and re-adds revive the tombstoned row instead of hard-deleting or duplicating it.
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $sharp = (new Tag())->setNew();
        $sharp->name = 'sharp';
        $heavy = (new Tag())->setNew();
        $heavy->name = 'heavy';
        $gadget->tag = Collection::create([$sharp, $heavy]);
        $this->em()->save($gadget);                     // two links inserted -> changed_at 1000, 2000

        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->tag = Collection::create([$sharp, $heavy]);
        $this->db->resetCalls();
        $this->em()->save($loaded);
        $this->assertSame([], $this->writesTo('gadget_tag'), 'Re-saving an unchanged active link should write nothing');

        $link = GadgetTag::on($this->db)->filter(
            Filter::all(Filter::equal('gadget_id', $gadget->id), Filter::equal('tag_id', $heavy->id))
        )->first()->setNew(false);
        $this->em()->save($link->delete());             // soft-deleted -> changed_at 3000

        $this->assertSame(
            [
                ['tag_id' => $sharp->id, 'deleted' => 'n', 'changed_at' => 1000],
                ['tag_id' => $heavy->id, 'deleted' => 'y', 'changed_at' => 3000],
            ],
            $this->rows('SELECT tag_id, deleted, changed_at FROM gadget_tag ORDER BY tag_id'),
            'Detaching a soft-delete link should mark it deleted and re-stamp changed_at, leaving the other link alone'
        );

        $readded = Gadget::on($this->db)->first()->setNew(false);
        $readded->tag = Collection::create([$sharp, $heavy]);
        $this->em()->save($readded);                    // revived -> changed_at 4000

        $this->assertSame(
            [
                ['tag_id' => $sharp->id, 'deleted' => 'n', 'changed_at' => 1000],
                ['tag_id' => $heavy->id, 'deleted' => 'n', 'changed_at' => 4000],
            ],
            $this->rows('SELECT tag_id, deleted, changed_at FROM gadget_tag ORDER BY tag_id'),
            'Re-adding a detached link should revive the existing row'
        );
    }

    public function testSoftDeleteJunctionWithSurrogateKeyRevivesAndDetachesThroughReconcile()
    {
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $sharp = (new Badge())->setNew();
        $sharp->name = 'sharp';
        $heavy = (new Badge())->setNew();
        $heavy->name = 'heavy';
        $gadget->badge = Collection::create([$sharp, $heavy]);
        $this->em()->save($gadget);                     // two links inserted -> changed_at 1000, 2000

        $heavyLinkId = GadgetBadge::on($this->db)
            ->filter(Filter::all(Filter::equal('gadget_id', $gadget->id), Filter::equal('badge_id', $heavy->id)))
            ->first()
            ->id;

        // Sync the membership down to just "sharp"; reconcile must soft-delete heavy's link scoped by its
        // surrogate id, not by gadget_id/badge_id
        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->badge = Collection::create([$sharp]);
        $this->em()->save($loaded);                     // heavy soft-deleted -> changed_at 3000

        $this->assertSame(
            [
                ['badge_id' => $sharp->id, 'deleted' => 'n', 'changed_at' => 1000],
                ['badge_id' => $heavy->id, 'deleted' => 'y', 'changed_at' => 3000],
            ],
            $this->rows('SELECT badge_id, deleted, changed_at FROM gadget_badge ORDER BY badge_id'),
            'Removing a surrogate-keyed soft-delete link should mark it deleted and re-stamp changed_at'
        );

        // Re-add heavy; reconcile must revive the tombstoned row, again scoped by its surrogate id
        $readded = Gadget::on($this->db)->first()->setNew(false);
        $readded->badge = Collection::create([$sharp, $heavy]);
        $this->em()->save($readded);                    // heavy revived -> changed_at 4000

        $this->assertSame(
            [
                ['badge_id' => $sharp->id, 'deleted' => 'n', 'changed_at' => 1000],
                ['badge_id' => $heavy->id, 'deleted' => 'n', 'changed_at' => 4000],
            ],
            $this->rows('SELECT badge_id, deleted, changed_at FROM gadget_badge ORDER BY badge_id'),
            'Re-adding a detached surrogate-keyed link should revive the existing row'
        );

        $this->assertSame(
            [['id' => $heavyLinkId, 'c' => 1]],
            $this->rows(
                'SELECT id, COUNT(*) AS c FROM gadget_badge WHERE badge_id = ' . $heavy->id . ' GROUP BY id'
            ),
            'Reviving must reuse the original junction row, keeping its surrogate id instead of inserting a duplicate'
        );
    }

    public function testDetachingALoadedTargetDoesNotReviveALinkSoftDeletedOutOfBand()
    {
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $sharp = (new Badge())->setNew();
        $sharp->name = 'sharp';
        $heavy = (new Badge())->setNew();
        $heavy->name = 'heavy';
        $gadget->badge = Collection::create([$sharp, $heavy]);
        $this->em()->save($gadget);                         // two links inserted -> changed_at 1000, 2000

        // Load the gadget and materialize its membership while both links are still live, so the loaded
        // "heavy" instance lands in the collection's base, then detach it.
        $loaded = Gadget::on($this->db)->first()->setNew(false);
        foreach ($loaded->badge as $badge) {
            if ((int) $badge->id === $heavy->id) {
                $loaded->badge->detach($badge);
            }
        }

        // Out of band (as a second repository would), soft-delete heavy's link before this save runs.
        $this->db->prepexec(
            "UPDATE gadget_badge SET deleted = 'y', changed_at = 7777 WHERE gadget_id = ? AND badge_id = ?",
            [$gadget->id, $heavy->id]
        );

        $this->em()->save($loaded);

        $this->assertSame(
            [
                ['badge_id' => $sharp->id, 'deleted' => 'n', 'changed_at' => 1000],
                ['badge_id' => $heavy->id, 'deleted' => 'y', 'changed_at' => 7777],
            ],
            $this->rows('SELECT badge_id, deleted, changed_at FROM gadget_badge ORDER BY badge_id'),
            'A detached, already soft-deleted link must stay deleted - not be revived by the merge-mode save'
        );
    }

    public function testSoftDeleteHasManyChildrenAreSoftDeletedOnSyncAndDetach()
    {
        // stamped_note is a HasMany child carrying a `deleted` column, so removing a note keeps its row and
        // marks deleted = 'y' rather than hard-deleting it.
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $first = (new StampedNote())->setNew();
        $first->text = 'first';
        $second = (new StampedNote())->setNew();
        $second->text = 'second';
        $stamped->stamped_note = Collection::create([$first, $second]);
        $this->em()->save($stamped);

        $loaded = Stamped::on($this->db)->first()->setNew(false);
        $loaded->stamped_note = (new Collection())->sync([]);
        $this->em()->save($loaded);
        $this->assertSame(
            [
                ['text' => 'first', 'deleted' => 'y'],
                ['text' => 'second', 'deleted' => 'y'],
            ],
            $this->rows('SELECT text, deleted FROM stamped_note ORDER BY id'),
            'sync([]) should soft-delete every orphaned child rather than removing its row'
        );

        $third = (new StampedNote())->setNew();
        $third->text = 'third';
        $loaded->stamped_note = Collection::create([$third]);
        $this->em()->save($loaded);
        $this->assertSame(
            'n',
            $this->rows("SELECT deleted FROM stamped_note WHERE text = 'third'")[0]['deleted'],
            'A newly attached child should be inserted active'
        );

        // Detaching soft-deletes the child rather than removing it, since a HasMany link is the child row itself.
        $loaded->stamped_note = (new Collection())->detach($third);
        $this->em()->save($loaded);
        $this->assertSame(
            'y',
            $this->rows("SELECT deleted FROM stamped_note WHERE text = 'third'")[0]['deleted'],
            'Detaching a soft-delete child should mark it deleted rather than removing its row'
        );
    }

    // -----------------------------------------------------------------------------------------------------
    // Column behaviors and the changed_at stamp
    // -----------------------------------------------------------------------------------------------------

    public function testColumnBehaviorConvertsValuesOnInsertAndUpdate()
    {
        $flag = (new Flag())->setNew();
        $flag->label = 'shiny';
        $flag->enabled = true;
        $this->em()->save($flag);

        $this->assertTrue($flag->enabled, 'The model value should remain in its PHP form after save');
        $this->assertSame(
            [['label' => 'shiny', 'enabled' => 'y']],
            $this->rows('SELECT label, enabled FROM flag'),
            'BoolCast should convert true to its database value on insert'
        );

        $flag->enabled = false;
        $this->em()->save($flag);
        $this->assertSame(
            [['enabled' => 'n']],
            $this->rows('SELECT enabled FROM flag'),
            'BoolCast should convert the new value on update'
        );
    }

    public function testChangedAtIsStampedOnEveryWriteButNotWhenNothingChanged()
    {
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Widget';
        $this->em()->save($stamped);                    // insert -> changed_at 1s
        $this->assertInstanceOf(
            DateTimeInterface::class,
            $stamped->changed_at,
            'The stamp should be a DateTime on the model'
        );
        $this->assertSame(1, $stamped->changed_at->getTimestamp(), 'The insert should stamp changed_at');
        $this->assertSame(
            [['name' => 'Widget', 'changed_at' => 1000]],
            $this->rows('SELECT name, changed_at FROM stamped'),
            'The DateTime should be converted to a millisecond timestamp on the way to the database'
        );

        $stamped->name = 'Gizmo';
        $this->em()->save($stamped);                    // update -> changed_at 2s
        $this->assertSame(2, $stamped->changed_at->getTimestamp(), 'An update should re-stamp changed_at');

        $this->db->resetCalls();
        $this->em()->save($stamped);
        $this->assertSame(2, $stamped->changed_at->getTimestamp(), 'An unchanged save must not re-stamp changed_at');
        $this->assertSame([], $this->writesTo('stamped'), 'An unchanged save must not write the row');

        // Reassigning only a relation leaves the parent row's own data untouched, so it is neither
        // re-stamped nor re-written.
        $loaded = Stamped::on($this->db)->first()->setNew(false);
        $loaded->stamped_note = new Collection();
        $this->db->resetCalls();
        $this->em()->save($loaded);
        $this->assertSame(
            [],
            $this->writesTo('stamped'),
            'A relation-only reassignment must not UPDATE the parent row'
        );
    }

    // -----------------------------------------------------------------------------------------------------
    // "Dumb" delete: no implicit cascade, explicit intent honoured
    // -----------------------------------------------------------------------------------------------------

    public function testDeletingAModelNeverCascadesToItsAssignedParentOrChildrenAndLeavesJunctionsIntact()
    {
        // A gadget linked to a sticker. Assign a brand-new BelongsTo parent, then delete the gadget. The
        // parent is independent and unmodified, so it must not be persisted, and the existing junction row
        // must be left in place (orphaned, not purged) — deletion does not touch junctions.
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $sticker = (new Sticker())->setNew();
        $sticker->label = 'fragile';
        $gadget->sticker = Collection::create([$sticker]);
        $this->em()->save($gadget);
        $gadgetId = $gadget->id;

        $newParent = (new Workshop())->setNew();
        $newParent->name = 'Acme';

        /** @var Model $loadedGadget */
        $loadedGadget = Gadget::on($this->db)->first()->setNew(false);
        $loadedGadget->workshop = $newParent;

        $this->db->resetCalls();
        $this->em()->save($loadedGadget->delete());

        $this->assertSame(
            [['method' => 'delete', 'table' => 'gadget', 'condition' => ['id = ?' => $gadgetId]]],
            $this->db->calls,
            'A deleted model should issue only its own DELETE; an assigned parent must not be cascaded'
        );
        $this->assertTrue($newParent->isNew(), 'An assigned parent must not be persisted while deleting');
        $this->assertSame(
            [['gadget_id' => $gadgetId, 'sticker_id' => $sticker->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'Deleting the owner must leave its existing junction rows untouched (orphaned)'
        );

        // A workshop with a brand-new assigned HasMany child, deleted in one save. The child is not marked
        // for deletion, so it must not be persisted onto a foreign key that is about to vanish.
        $workshop = $this->savedWorkshop('Globex');
        $workshopId = $workshop->id;
        $orphan = (new Gadget())->setNew();
        $orphan->name = 'Orphan';

        /** @var Model $loadedWorkshop */
        $loadedWorkshop = Workshop::on($this->db)->filter(Filter::equal('name', 'Globex'))->first()->setNew(false);
        $loadedWorkshop->gadget = Collection::create([$orphan]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('should not carry attachments');
        $this->em()->save($loadedWorkshop->delete());
    }

    public function testAssigningANewManyToManyTargetWhileDeletingTheOwnerStillLinksIt()
    {
        // The EntityManager is "dumb": an explicitly assigned many-to-many link is honoured even while the
        // owner is being deleted. The target is persisted and its junction row written before the owner's
        // DELETE, leaving the link orphaned. Removing a source's links is a separate, explicit operation.
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);
        $gadgetId = $gadget->id;

        $late = (new Sticker())->setNew();
        $late->label = 'late';

        /** @var Model $loaded */
        $loaded = Gadget::on($this->db)->first()->setNew(false);
        $loaded->sticker = Collection::create([$late]);

        $this->db->resetCalls();
        $this->em()->save($loaded->delete());

        $this->assertFalse($late->isNew(), 'The explicitly assigned target should still be persisted');
        $this->assertSame(
            ['sticker', 'gadget_sticker', 'gadget'],
            array_column($this->db->calls, 'table'),
            'The link should be written before the owner is deleted'
        );
        $this->assertSame(
            ['insert', 'insert', 'delete'],
            array_column($this->db->calls, 'method'),
            'The target and its junction row should be inserted before the owner is deleted'
        );
        $this->assertSame([], $this->rows('SELECT * FROM gadget'), 'The owner itself should be gone');
        $this->assertSame(
            [['gadget_id' => $gadgetId, 'sticker_id' => $late->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'The honoured link should remain behind as an orphaned junction row'
        );
    }

    public function testExplicitDeletionsAndInPlaceEditsAcrossAGraphAreAllApplied()
    {
        // A gadget that owns a workshop (BelongsTo) and is linked to a tag through the soft-delete junction.
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $gadget->workshop = $workshop;
        $tag = (new Tag())->setNew();
        $tag->name = 'sharp';
        $gadget->tag = Collection::create([$tag]);
        $this->em()->save($gadget);                     // link inserted -> changed_at 1000
        $gadgetId = $gadget->id;
        $workshopId = $workshop->id;
        $tagId = $tag->id;

        // Detach the link out of band, soft-deleting the junction row.
        $link = GadgetTag::on($this->db)->filter(Filter::equal('gadget_id', $gadget->id))->first()->setNew(false);
        $this->em()->save($link->delete());             // soft-deleted -> changed_at 2000

        // In one save: edit the (independent) parent in place, mark the target deleted and delete the gadget.
        // The parent outlives the gadget, so its edit is still applied — after the gadget is gone. The target
        // is explicitly deleted, so it is removed too. The soft-deleted junction row is left as a tombstone.
        $loaded = Gadget::on($this->db)->with('workshop')->first()->setNew(false);
        $loaded->workshop->setNew(false);
        $loaded->workshop->name = 'Globex';
        $loaded->tag = Collection::create([$tag->delete()]);
        $this->em()->save($loaded->delete());

        $this->assertSame([], $this->rows('SELECT * FROM gadget'), 'The explicitly deleted gadget should be gone');
        $this->assertSame([], $this->rows('SELECT * FROM tag'), 'The explicitly deleted target should be gone');
        $this->assertSame(
            [['id' => $workshopId, 'name' => 'Globex']],
            $this->rows('SELECT id, name FROM workshop'),
            'An in-place edit to an independent parent should be applied even as its owner is deleted'
        );
        $this->assertSame(
            [['gadget_id' => $gadgetId, 'tag_id' => $tagId, 'deleted' => 'y', 'changed_at' => 2000]],
            $this->rows('SELECT gadget_id, tag_id, deleted, changed_at FROM gadget_tag'),
            'The soft-deleted junction row should remain as an untouched tombstone'
        );
    }

    public function testDeletingAModelAlsoDeletesAChildExplicitlyMarkedDeleted()
    {
        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $workshop->gadget = Collection::create([$gadget]);
        $this->em()->save($workshop);
        $this->assertNotEmpty($this->rows('SELECT * FROM gadget'), 'precondition: the child exists');

        $loadedWorkshop = Workshop::on($this->db)->first()->setNew(false);
        $loadedGadget = Gadget::on($this->db)->first()->setNew(false);
        $loadedWorkshop->gadget = Collection::create([$loadedGadget->delete()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('should not carry attachments');
        $this->em()->save($loadedWorkshop->delete());
    }

    public function testModifyDeletedRelation()
    {
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $note = (new StampedNote())->setNew();
        $note->text = 'initial';
        $stamped->stamped_note = Collection::create([$note]);
        (new TickingEntityManager($this->db))->save($stamped);
        $this->em()->save($stamped);

        $loadedStamped = Stamped::on($this->db)->first()->setNew(false);
        foreach ($loadedStamped->stamped_note as $note) {
            $note->text = 'modified';
            $note->delete();
        }

        $loadedStamped->delete();
        $this->em()->save($loadedStamped);

        $this->assertSame(
            [['text' => 'modified', 'deleted' => 'y']],
            $this->rows('SELECT text, deleted FROM stamped_note'),
            'The explicitly deleted child must be soft deleted and its modifications persisted'
        );
        $this->assertSame([], $this->rows('SELECT * FROM stamped'), 'The parent should be gone');
    }

    // -----------------------------------------------------------------------------------------------------
    // Change tracking, transactions and graph guards
    // -----------------------------------------------------------------------------------------------------

    public function testReassigningALoadedRelationTracksPendingChanges()
    {
        $this->savedWorkshop('Acme');
        $loaded = Workshop::on($this->db)->first()->setNew(false);

        // Replace the (still-Closure) relation without first reading it, so its loader never runs.
        $loaded->gadget = Collection::create([(new Gadget())->setNew()]);

        $this->assertTrue(
            $loaded->gadget->hasPendingChanges(),
            'Reassigning a relation should mark it modified so saveGraph cascades the new value'
        );
    }

    public function testUpdatingOnlyAChildDoesNotRewriteTheParent()
    {
        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $workshop->gadget = Collection::create([$gadget]);
        $this->em()->save($workshop);

        $this->db->resetCalls();
        $gadget->name = 'Wrench';
        $this->em()->save($gadget);

        $this->assertSame([], $this->writesTo('workshop'), 'Updating only a child must not re-write its parent');
        $this->assertSame(
            [[
                'method'    => 'update',
                'table'     => 'gadget',
                'data'      => ['name' => 'Wrench'],
                'condition' => ['id = ?' => $gadget->id],
            ]],
            $this->db->calls,
            'Only the child should be written'
        );
    }

    public function testSaveJoinsAnOuterTransactionInsteadOfNesting()
    {
        $a = (new Workshop())->setNew();
        $a->name = 'Acme';
        $b = (new Workshop())->setNew();
        $b->name = 'Globex';

        $em = $this->em();
        $this->db->transaction(function () use ($em, $a, $b): void {
            $em->save($a);
            $em->save($b);
        });

        $this->assertSame(
            [['name' => 'Acme'], ['name' => 'Globex']],
            $this->rows('SELECT name FROM workshop ORDER BY id'),
            'Both rows should be persisted by the outer transaction; save() should join it rather than nesting'
        );
    }

    public function testAFailedCascadeRollsBackTheWholeGraph()
    {
        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';

        // A gadget without a name violates the NOT NULL constraint and fails mid-cascade.
        $workshop->gadget = Collection::create([(new Gadget())->setNew()]);

        try {
            $this->em()->save($workshop);
            $this->fail('Expected the failing save to throw');
        } catch (Exception $e) {
            // expected
        }

        $this->assertSame(
            [],
            $this->rows('SELECT * FROM workshop'),
            'The parent insert should be rolled back with the child'
        );
    }

    public function testSavingACyclicGraphThrows()
    {
        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';

        // Build a cycle: the workshop owns the gadget and the gadget points back at the same instance.
        $workshop->gadget = Collection::create([$gadget]);
        $gadget->workshop = $workshop;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('loop detected');

        $this->em()->save($workshop);
    }

    public function testDeletingALoadedModelLeavesItsEagerLoadedRelationIntact()
    {
        // Deleting a model does not cascade to its relations, not even one eagerly loaded alongside it.
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $workshop = (new Workshop())->setNew();
        $workshop->name = 'Acme';
        $gadget->workshop = $workshop;
        $this->em()->save($gadget);
        $workshopId = $workshop->id;

        $loaded = Gadget::on($this->db)->with('workshop')->first()->setNew(false);
        $this->em()->save($loaded->delete());

        $this->assertSame([], $this->rows('SELECT * FROM gadget'), 'The deleted model should be removed');
        $this->assertSame(
            [['id' => $workshopId, 'name' => 'Acme']],
            $this->rows('SELECT id, name FROM workshop'),
            'An eagerly-loaded relation of a deleted model must be left intact'
        );
    }

    public function testSaveRejectsARowThatChangedSinceTheBaseline()
    {
        $writer = new TickingEntityManager($this->db);
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'stamped';
        $writer->save($stamped);                                // changed_at -> 1000

        // The state Actor A sees when the edit form is rendered
        $formState = Stamped::on($this->db)->first()->setNew(false);
        $baseline = $formState->changed_at;

        // Actor B concurrently updates the same row, bumping its changed_at past the baseline
        $concurrent = new TickingEntityManager($this->db);
        $editedByB = Stamped::on($this->db)->first()->setNew(false);
        $editedByB->name = 'edited by B';
        $concurrent->save($editedByB);                          // changed_at -> 2000

        // Actor A submits: the controller reloads the current row and applies A's change onto it. Since the
        // reloaded row now carries B's newer changed_at, saving it against A's baseline must throw.
        $em = new TickingEntityManager($this->db, $baseline);
        $editedByA = Stamped::on($this->db)->first()->setNew(false);
        $editedByA->name = 'edited by A';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed after baseline');
        $em->save($editedByA);
    }

    public function testSaveRejectsSoftDeletingAJunctionLinkAddedSinceTheBaseline()
    {
        $gadget = (new Gadget())->setNew();
        $gadget->name = 'Spanner';
        $sharp = (new Tag())->setNew();
        $sharp->name = 'sharp';
        $gadget->tag = Collection::create([$sharp]);
        (new TickingEntityManager($this->db))->save($gadget);   // link (gadget, sharp) stamped

        // The state Actor A sees: the newest junction changed_at across the gadget's links
        $storedLink = GadgetTag::on($this->db)->first();
        $baseline = $storedLink->changed_at;

        // Actor B concurrently links a second tag, adding a junction row A never saw
        $heavy = (new Tag())->setNew();
        $heavy->name = 'heavy';
        $editedByB = Gadget::on($this->db)->first()->setNew(false);
        $editedByB->tag = (new Collection())->attach($heavy);
        (new TickingEntityManager($this->db))->save($editedByB);

        // Actor A submits, syncing the gadget's tags down to just "sharp"; reconcile hits B's newer link
        $em = new TickingEntityManager($this->db, $baseline);
        $editedByA = Gadget::on($this->db)->first()->setNew(false);
        $editedByA->tag = (new Collection())->sync([$sharp]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed after baseline');
        $em->save($editedByA);
    }

    public function testSaveRejectsSoftDeletingAHasManyChildAddedSinceTheBaseline()
    {
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $first = (new StampedNote())->setNew();
        $first->text = 'first';
        $stamped->stamped_note = Collection::create([$first]);
        (new TickingEntityManager($this->db))->save($stamped);  // note "first" stamped

        // The state Actor A sees: the newest note changed_at
        $storedNote = StampedNote::on($this->db)->first();
        $baseline = $storedNote->changed_at;

        // Actor B concurrently adds a second note A never saw
        $second = (new StampedNote())->setNew();
        $second->text = 'second';
        $editedByB = Stamped::on($this->db)->first()->setNew(false);
        $editedByB->stamped_note = (new Collection())->attach($second);
        (new TickingEntityManager($this->db))->save($editedByB);

        // Actor A submits, syncing the notes down to just "first"; reconcile hits B's newer note
        $em = new TickingEntityManager($this->db, $baseline);
        $editedByA = Stamped::on($this->db)->first()->setNew(false);
        $editedByA->stamped_note = (new Collection())->sync([$first]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has changed after baseline');
        $em->save($editedByA);
    }

    // -----------------------------------------------------------------------------------------------------
    // In-place modification of loaded members and the query() accessor
    // -----------------------------------------------------------------------------------------------------

    public function testInPlaceEditOfAMaterializedChildIsPersisted()
    {
        // No attach/detach/sync — just materialize the children and edit one in place. hasPendingChanges()
        // must detect it and getMembersToSave() must cascade it.
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $note = (new StampedNote())->setNew();
        $note->text = 'original';
        $stamped->stamped_note = Collection::create([$note]);
        $this->em()->save($stamped);

        $loaded = Stamped::on($this->db)->first()->setNew(false);
        foreach ($loaded->stamped_note as $child) {   // materializes the collection, marking members loaded
            $child->text = 'edited';
        }

        $this->em()->save($loaded);

        $this->assertSame(
            [['text' => 'edited', 'deleted' => 'n']],
            $this->rows('SELECT text, deleted FROM stamped_note'),
            'An in-place edit to a materialized child must be persisted without any attach/detach'
        );
    }

    public function testSavingAMaterializedButUnmodifiedChildCollectionWritesNothing()
    {
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $note = (new StampedNote())->setNew();
        $note->text = 'original';
        $stamped->stamped_note = Collection::create([$note]);
        $this->em()->save($stamped);

        $loaded = Stamped::on($this->db)->first()->setNew(false);
        $this->assertCount(1, $loaded->stamped_note, 'precondition: the child collection is materialized');

        $this->db->resetCalls();
        $this->em()->save($loaded);

        $this->assertSame(
            [],
            $this->writesTo('stamped_note'),
            'Unmodified materialized children must not be rewritten'
        );
        $this->assertSame([], $this->writesTo('stamped'), 'An unmodified parent must not be rewritten');
    }

    public function testDeletingAnOwnerSoftDeletesADetachedChild()
    {
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $keep = (new StampedNote())->setNew();
        $keep->text = 'keep';
        $drop = (new StampedNote())->setNew();
        $drop->text = 'drop';
        $stamped->stamped_note = Collection::create([$keep, $drop]);
        $this->em()->save($stamped);

        // Detach one child and delete the owner in a single save; the detached child must still be removed.
        $loadedDrop = StampedNote::on($this->db)->filter(Filter::equal('text', 'drop'))->first()->setNew(false);
        $loaded = Stamped::on($this->db)->first()->setNew(false);
        $loaded->stamped_note->detach($loadedDrop);
        $this->em()->save($loaded->delete());

        $this->assertSame([], $this->rows('SELECT * FROM stamped'), 'The owner should be hard-deleted');
        $this->assertSame(
            [
                ['text' => 'keep', 'deleted' => 'n'],
                ['text' => 'drop', 'deleted' => 'y'],
            ],
            $this->rows('SELECT text, deleted FROM stamped_note ORDER BY id'),
            'A detached child must be soft-deleted even while its owner is being deleted'
        );
    }

    public function testDeletingAnOwnerSoftDeletesEveryChildOfARelationSyncedToAnEmptySet()
    {
        $stamped = (new Stamped())->setNew();
        $stamped->name = 'Manual';
        $first = (new StampedNote())->setNew();
        $first->text = 'first';
        $second = (new StampedNote())->setNew();
        $second->text = 'second';
        $stamped->stamped_note = Collection::create([$first, $second]);
        $this->em()->save($stamped);

        // Sync the children down to nothing and delete the owner in a single save.
        $loaded = Stamped::on($this->db)->first()->setNew(false);
        $loaded->stamped_note->sync([]);
        $this->em()->save($loaded->delete());

        $this->assertSame([], $this->rows('SELECT * FROM stamped'), 'The owner should be hard-deleted');
        $this->assertSame(
            [
                ['text' => 'first', 'deleted' => 'y'],
                ['text' => 'second', 'deleted' => 'y'],
            ],
            $this->rows('SELECT text, deleted FROM stamped_note ORDER BY id'),
            'Every stored child must be removed when the owner of an empty-synced relation is deleted'
        );
    }

    public function testQueryReturnsTheSourceQuery()
    {
        $query = Stamped::on($this->db);

        $this->assertSame(
            $query,
            Collection::fromLoaded($query)->query(),
            'query() should return the query the collection was created from'
        );
    }

    public function testQueryThrowsWhenThereIsNoPendingQuery()
    {
        $this->expectException(LogicException::class);
        (new Collection())->query();
    }
}
