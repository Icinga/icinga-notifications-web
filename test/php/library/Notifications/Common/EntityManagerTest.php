<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Common;

use DateTimeInterface;
use Exception;
use Icinga\Module\Notifications\Common\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Charm;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Flag;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Gadget;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\GadgetTag;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Pairing;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\RecordingConnection;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Stamped;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Sticker;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Tag;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\TickingEntityManager;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Trinket;
use Tests\Icinga\Module\Notifications\Lib\EntityManager\Workshop;

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
            . 'CREATE TABLE flag (id INTEGER PRIMARY KEY AUTOINCREMENT,
                label VARCHAR NOT NULL, enabled VARCHAR NOT NULL);'
            . 'CREATE TABLE stamped (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL, changed_at INTEGER);'
            . 'CREATE TABLE stamped_note ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, stamped_id INTEGER NOT NULL, text VARCHAR NOT NULL);'
            . 'CREATE TABLE trinket (id BLOB PRIMARY KEY, name VARCHAR NOT NULL);'
            . 'CREATE TABLE charm (id INTEGER PRIMARY KEY AUTOINCREMENT,
                trinket_id BLOB NOT NULL, label VARCHAR NOT NULL);'
            . 'CREATE TABLE pairing ('
            . 'left_id INTEGER NOT NULL, right_id INTEGER NOT NULL, label VARCHAR NOT NULL,'
            . ' PRIMARY KEY (left_id, right_id));'
            . 'CREATE TABLE tag (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_tag ('
            . 'gadget_id INTEGER NOT NULL, tag_id INTEGER NOT NULL,'
            . " changed_at INTEGER NOT NULL, deleted VARCHAR NOT NULL DEFAULT 'n',"
            . ' PRIMARY KEY (gadget_id, tag_id));'
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

    public function testHydratedModelIsLoadedAndUnmodified()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $loaded = Workshop::on($this->db)->first();

        $this->assertNotNull($loaded);
        $this->assertFalse($loaded->isNew(), 'A hydrated model is not new');
        $this->assertFalse($loaded->isModified(), 'A freshly hydrated model has no changes');
    }

    public function testUpdateWritesOnlyChangedColumns()
    {
        $gadget = new Gadget();
        $gadget->workshop_id = 5;
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);

        $gadget->name = 'Wrench';
        $this->assertSame(
            ['name' => true],
            $gadget->getModifiedProperties(),
            'Only the changed column is tracked as modified'
        );

        $this->em()->save($gadget);

        $this->assertFalse($gadget->isModified(), 'The model is unmodified after an update');
        $this->assertSame(
            [['workshop_id' => 5, 'name' => 'Wrench']],
            $this->rows('SELECT workshop_id, name FROM gadget'),
            'The unchanged column is preserved'
        );
    }

    public function testNoOpSaveOnUnmodifiedModelDoesNothing()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $this->em()->save($workshop);

        $this->assertSame([['id' => 1, 'name' => 'Acme']], $this->rows('SELECT * FROM workshop'));
    }

    public function testDeleteNewModel()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop->markDeleted());

        $this->assertEmpty($this->db->calls, 'Marking a never-saved model deleted and saving issues no writes');
        $this->assertSame(
            [],
            $this->rows('SELECT * FROM workshop'),
            'A never-saved model marked deleted leaves no row behind'
        );
    }

    public function testDeleteNewSoftDeleteModelIsANoOpAndInsertsNoTombstone()
    {
        // gadget_tag carries a `deleted` column. A never-saved model marked deleted must still be a no-op:
        // it must not insert a deleted = 'y' tombstone row for something that never existed.
        $gadgetTag = new GadgetTag();
        $gadgetTag->gadget_id = 1;
        $gadgetTag->tag_id = 1;

        $this->em()->save($gadgetTag->markDeleted());

        $this->assertEmpty($this->db->calls, 'Marking a never-saved soft-delete model deleted issues no writes');
        $this->assertSame(
            [],
            $this->rows('SELECT * FROM gadget_tag'),
            'No tombstone row is inserted for a never-saved soft-delete model'
        );
    }

    public function testSaveOfModelMarkedDeletedHardDeletesWhenItHasNoDeletedColumn()
    {
        // workshop has no `deleted` column, so useSoftDelete() is false: the $em->save($model->markDeleted())
        // flow must remove the row outright.
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);

        $this->em()->save($workshop->markDeleted());

        $this->assertSame(
            [],
            $this->rows('SELECT * FROM workshop'),
            'A model with no deleted column is hard-deleted'
        );
    }

    public function testSaveOfModelMarkedDeletedSoftDeletesWhenItHasADeletedColumn()
    {
        // gadget_tag carries a `deleted` column, so useSoftDelete() is true: the $em->save($model->markDeleted())
        // flow must keep the row and flip deleted to 'y' (stamping changed_at) rather than removing it.
        $gadgetTag = new GadgetTag();
        $gadgetTag->gadget_id = 1;
        $gadgetTag->tag_id = 1;
        $this->em()->save($gadgetTag); // changed_at -> 1000

        $this->em()->save($gadgetTag->markDeleted()); // soft-deleted, changed_at -> 2000

        $this->assertSame(
            [['gadget_id' => 1, 'tag_id' => 1, 'deleted' => 'y', 'changed_at' => 2000]],
            $this->rows('SELECT gadget_id, tag_id, deleted, changed_at FROM gadget_tag'),
            'A model with a deleted column is kept and marked deleted = y, not removed'
        );
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

        $this->assertSame($workshop->id, $spanner->workshop_id);
        $this->assertSame($workshop->id, $wrench->workshop_id);
        $this->assertSame(
            [
                ['name' => 'Spanner', 'workshop_id' => $workshop->id],
                ['name' => 'Wrench', 'workshop_id' => $workshop->id],
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

    public function testBelongsToAssigningNullClearsTheForeignKey()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $gadget->workshop = $workshop;
        $this->em()->save($gadget);
        $this->assertSame($workshop->id, $gadget->workshop_id, 'The link is established before it is cleared');

        // Load fresh and clear the relation by assigning null. The relation property holds null (not a
        // lazy-loader closure), so saveGraph sees it as an explicit assignment and nulls the foreign key.
        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->workshop = null;
        $this->em()->save($loaded);

        $this->assertSame(
            [['name' => 'Spanner', 'workshop_id' => null]],
            $this->rows('SELECT name, workshop_id FROM gadget'),
            'Assigning null to a BelongsTo nulls the foreign key on update'
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
            [['gadget_id' => $gadget->id, 'sticker_id' => $sticker->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker')
        );
    }

    public function testManyToManyTargetIsNotPollutedWithJunctionForeignKey()
    {
        // The junction's foreign key (gadget_id) lives on the junction table, not on the target
        // (sticker). It must not leak onto the target as a stray property — that would surface
        // a column the target doesn't own and confuse any later read of the model.
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];

        $this->em()->save($gadget);

        $this->assertFalse(
            $sticker->hasProperty('gadget_id'),
            'A many-to-many target must not carry the junction foreign key as a stray property'
        );
    }

    public function testManyToManyBatchesMultipleLinksIntoASingleInsert()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $fragile = new Sticker();
        $fragile->label = 'fragile';
        $thisSideUp = new Sticker();
        $thisSideUp->label = 'this side up';
        $heavy = new Sticker();
        $heavy->label = 'heavy';
        $gadget->stickers = [$fragile, $thisSideUp, $heavy];

        $this->em()->save($gadget);

        $junctionInserts = array_filter(
            $this->db->calls,
            fn ($call) => $call['method'] === 'insert' && $call['table'] === 'gadget_sticker'
        );
        $this->assertSame(
            [],
            $junctionInserts,
            'Multiple links must not be written one insert() per target'
        );

        $this->assertSame(
            [
                ['gadget_id' => $gadget->id, 'sticker_id' => $fragile->id],
                ['gadget_id' => $gadget->id, 'sticker_id' => $thisSideUp->id],
                ['gadget_id' => $gadget->id, 'sticker_id' => $heavy->id],
            ],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker ORDER BY sticker_id'),
            'All link rows must be present after the batched insert'
        );
    }

    public function testManyToManyDoesNotDuplicateAnExistingLink()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];

        $this->em()->save($gadget);

        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();

        $loaded->stickers = [$sticker];
        $this->em()->save($loaded);

        $this->assertSame(
            [['gadget_id' => $gadget->id, 'sticker_id' => $sticker->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'Re-saving an unchanged link must not duplicate the junction row'
        );
    }

    public function testManyToManyReassignmentReplacesThePreviousLinks()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $fragile = new Sticker();
        $fragile->label = 'fragile';
        $gadget->stickers = [$fragile];

        $this->em()->save($gadget);

        $heavy = new Sticker();
        $heavy->label = 'heavy';

        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->stickers = [$heavy];
        $loaded->stickers = array_merge($loaded->stickers, [$heavy]);
        $this->em()->save($loaded);

        $this->assertSame(
            [['gadget_id' => $gadget->id, 'sticker_id' => $heavy->id]],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'The reassigned set replaces the previous links'
        );
        $this->assertSame(
            2,
            (int) $this->rows('SELECT COUNT(*) AS c FROM sticker')[0]['c'],
            'Reconciling the junction must not delete the target rows'
        );
    }

    public function testManyToManyAssigningAnEmptySetClearsTheLinks()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];

        $this->em()->save($gadget);

        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->stickers = [];
        $this->em()->save($loaded);

        $this->assertSame(
            [],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'Assigning an empty set removes all links'
        );
    }

    public function testManyToManyAssigningNullClearsTheLinks()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];

        $this->em()->save($gadget);

        // Null collapses to the empty set on the way through asTraversable(), so it clears all links
        // just like assigning [] does.
        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->stickers = null;
        $this->em()->save($loaded);

        $this->assertSame(
            [],
            $this->rows('SELECT gadget_id, sticker_id FROM gadget_sticker'),
            'Assigning null to a many-to-many relation removes all links'
        );
    }

    public function testManyToManyReconciliationMatchesKeysReturnedAsStrings()
    {
        // Production drivers (MySQL/PgSQL) return keys as strings while persisted models hold them as
        // ints, so reconcile must treat those as the same link — re-saving an unchanged relation must
        // stay a no-op, not delete-and-reinsert. STRINGIFY_FETCHES reproduces that boundary here,
        // since sqlite otherwise returns ints on both sides and never exercises it.
        $db = new RecordingConnection([
            'db'      => 'sqlite',
            'dbname'  => ':memory:',
            'options' => [PDO::ATTR_STRINGIFY_FETCHES => true],
        ]);
        $db->exec(
            'CREATE TABLE gadget (id INTEGER PRIMARY KEY AUTOINCREMENT, workshop_id INTEGER, name VARCHAR NOT NULL);'
            . 'CREATE TABLE sticker (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_sticker (gadget_id INTEGER NOT NULL, sticker_id INTEGER NOT NULL);'
        );

        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];
        (new TickingEntityManager($db))->save($gadget);

        /** @var Gadget $loaded */
        $loaded = Gadget::on($db)->first();
        $loaded->stickers = [$sticker];

        $db->resetCalls();
        (new TickingEntityManager($db))->save($loaded);

        $junctionWrites = array_filter($db->calls, fn ($call) => $call['table'] === 'gadget_sticker');
        $this->assertSame(
            [],
            $junctionWrites,
            'An unchanged link must trigger no insert or delete even when keys come back as strings'
        );
        $this->assertSame(
            [['gadget_id' => '1', 'sticker_id' => '1']],
            $db->prepexec('SELECT gadget_id, sticker_id FROM gadget_sticker')->fetchAll(PDO::FETCH_ASSOC),
            'The single link is left intact'
        );
    }

    public function testManyToManyDeclaredByTableNameHardDeletesEvenWithDeletedColumn()
    {
        // A junction declared by table name resolves to a generic ipl-orm Junction, which the
        // EntityManager never treats as soft-delete: only a junction *model* that reports
        // isSoftDelete() can opt in. So even though the table carries a `deleted` column, the
        // generic Junction discards that metadata and the orphaned link is hard-deleted.
        $db = new RecordingConnection(['db' => 'sqlite', 'dbname' => ':memory:']);
        $db->exec(
            'CREATE TABLE gadget (id INTEGER PRIMARY KEY AUTOINCREMENT, workshop_id INTEGER, name VARCHAR NOT NULL);'
            . 'CREATE TABLE sticker (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR NOT NULL);'
            . 'CREATE TABLE gadget_sticker ('
            . 'gadget_id INTEGER NOT NULL, sticker_id INTEGER NOT NULL, deleted INTEGER NOT NULL DEFAULT 0);'
        );

        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];
        (new TickingEntityManager($db))->save($gadget);

        /** @var Gadget $loaded */
        $loaded = Gadget::on($db)->first();
        $loaded->stickers = [];
        (new TickingEntityManager($db))->save($loaded);

        $this->assertSame(
            [],
            $db->prepexec('SELECT gadget_id, sticker_id, deleted FROM gadget_sticker')->fetchAll(PDO::FETCH_ASSOC),
            'A table-name junction is a generic Junction, so the orphan is hard-deleted despite the column'
        );
    }

    public function testSoftDeleteJunctionMarksRemovedLinkDeletedAndStampsChangedAt()
    {
        // The tags relation goes through a junction model with a `deleted` column, so an orphaned link
        // is marked deleted = 'y' (the row stays) and changed_at is stamped, rather than hard-deleted.
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $first = new Tag();
        $first->name = 'sharp';
        $second = new Tag();
        $second->name = 'heavy';
        $gadget->tags = [$first, $second];

        $this->em()->save($gadget); // changed_at -> 1000

        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->tags = [$first];
        $this->em()->save($loaded); // changed_at -> 2000

        $this->assertSame(
            [
                ['tag_id' => 1, 'deleted' => 'n', 'changed_at' => 1000],
                ['tag_id' => 2, 'deleted' => 'y', 'changed_at' => 2000],
            ],
            $this->rows('SELECT tag_id, deleted, changed_at FROM gadget_tag ORDER BY tag_id'),
            'The removed link is soft-deleted and re-stamped; the kept link is left untouched'
        );
    }

    public function testSoftDeleteJunctionRevivesAReAddedLinkInsteadOfDuplicating()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $tag = new Tag();
        $tag->name = 'sharp';
        $gadget->tags = [$tag];
        $this->em()->save($gadget); // link created, changed_at -> 1000

        /** @var Gadget $removed */
        $removed = Gadget::on($this->db)->first();
        $removed->tags = [];
        $this->em()->save($removed); // link soft-deleted, changed_at -> 2000

        /** @var Gadget $readded */
        $readded = Gadget::on($this->db)->first();
        $readded->tags = [$tag];
        $this->em()->save($readded); // link revived, changed_at -> 3000

        $this->assertSame(
            [['tag_id' => 1, 'deleted' => 'n', 'changed_at' => 3000]],
            $this->rows('SELECT tag_id, deleted, changed_at FROM gadget_tag'),
            'Re-adding revives the existing row rather than inserting a duplicate'
        );
    }

    public function testSoftDeleteJunctionLeavesAnUnchangedActiveLinkUntouched()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';

        $tag = new Tag();
        $tag->name = 'sharp';
        $gadget->tags = [$tag];
        $this->em()->save($gadget);

        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->tags = [$tag];

        $this->db->resetCalls();
        $this->em()->save($loaded);

        $junctionWrites = array_filter($this->db->calls, fn ($call) => $call['table'] === 'gadget_tag');
        $this->assertSame(
            [],
            $junctionWrites,
            'Re-saving an unchanged active link writes nothing to the junction'
        );
    }

    public function testSaveWithinOuterTransactionDoesNotOpenNestedTransaction()
    {
        $a = new Workshop();
        $a->name = 'Acme';

        $b = new Workshop();
        $b->name = 'Globex';

        $em = $this->em();
        $this->db->transaction(function () use ($em, $a, $b): void {
            $em->save($a);
            $em->save($b);
        });

        $this->assertSame(
            [['name' => 'Acme'], ['name' => 'Globex']],
            $this->rows('SELECT name FROM workshop ORDER BY id'),
            'Both rows are persisted by the outer transaction; save() joined it instead of nesting'
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

    public function testChangedAtIsNotStampedWhenOnlyARelationWasReassigned()
    {
        // Save once so we have a loadable row with changed_at = 1s.
        $stamped = new Stamped();
        $stamped->name = 'Widget';
        $this->em()->save($stamped);

        // Load it fresh — the relation comes back as a closure-backed lazy loader.
        $loaded = null;
        foreach (Stamped::on($this->db)->execute() as $row) {
            $loaded = $row;
            break;
        }
        $this->assertInstanceOf(Stamped::class, $loaded);

        // Reassign only the relation — no own-column changes. The parent row's data
        // hasn't actually moved, so neither its `changed_at` nor its row should change.
        $loaded->notes = [];

        $this->db->resetCalls();
        $this->em()->save($loaded);

        $stampedWrites = array_values(
            array_filter(
                $this->db->calls,
                fn(array $c): bool => $c['table'] === 'stamped'
            )
        );
        $this->assertSame(
            [],
            $stampedWrites,
            'A relation-only reassignment must not emit an UPDATE on the parent row'
        );
        $this->assertSame(
            1,
            $loaded->changed_at->getTimestamp(),
            'changed_at must not be stamped when no column on the row changed'
        );
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

        $this->em()->save($trinket->markDeleted());

        $this->assertSame([], $this->rows('SELECT id FROM trinket'));
    }

    public function testReadingALazyRelationOnLoadedModelDoesNotMarkItModified()
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
            $loaded->isModified(),
            'Reading a lazily-loaded relation must not mark the model modified'
        );
    }

    public function testAssigningALazyRelationOnLoadedModelMarksItModifiedWithoutResolvingIt()
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
            $loaded->getModifiedProperties(),
            'Reassigning a relation marks it modified so saveGraph cascades the new value'
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
        $this->em()->save($w->markDeleted());

        $this->assertTrue($w->isNew(), 'Deleted model is treated as new again');
        $this->assertFalse($w->isModified(), 'Modified state was cleared');

        $w->name = 'Acme 2';
        $this->em()->save($w);
        $this->assertSame([['name' => 'Acme 2']], $this->rows('SELECT name FROM workshop'));
    }

    public function testDeleteClearsAutoIncrementKey()
    {
        $w = new Workshop();
        $w->name = 'Acme';
        $this->em()->save($w);
        $oldId = $w->id;

        $this->em()->save($w->markDeleted());

        $this->assertFalse($w->hasProperty('id'), 'The auto-increment key is cleared on delete');

        $w->name = 'Acme 2';
        $this->em()->save($w);

        $this->assertNotSame(
            $oldId,
            $w->id,
            'A save after delete receives a fresh auto-increment id rather than re-using the old key'
        );
    }

    public function testDeleteClearsCompoundKey()
    {
        $p = new Pairing();
        $p->left_id = 7;
        $p->right_id = 9;
        $p->label = 'A';
        $this->em()->save($p);

        $this->em()->save($p->markDeleted());

        $this->assertFalse($p->hasProperty('left_id'), 'Each part of a compound key is cleared');
        $this->assertFalse($p->hasProperty('right_id'), 'Each part of a compound key is cleared');
    }

    public function testDeleteClearsApplicationAssignedKey()
    {
        $id = hex2bin('deadbeefcafebabe1234567890abcdef');
        $trinket = new Trinket();
        $trinket->id = $id;
        $trinket->name = 'Amulet';
        $this->em()->save($trinket);

        $this->em()->save($trinket->markDeleted());

        $this->assertFalse($trinket->hasProperty('id'), 'The application-assigned key is cleared on delete');
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

        $this->em()->save($b->markDeleted());

        $this->assertSame(
            [['left_id' => 1, 'right_id' => 1, 'label' => 'A']],
            $this->rows('SELECT left_id, right_id, label FROM pairing'),
            'Only the row matching all key columns was deleted'
        );
    }

    public function testCompoundKeyInsertWritesBothKeyColumnsAndClearsModifiedProperties()
    {
        $p = new Pairing();
        $p->left_id = 7;
        $p->right_id = 9;
        $p->label = 'A';

        $this->em()->save($p);

        $this->assertFalse($p->isNew(), 'A saved compound-key model is no longer new');
        $this->assertFalse($p->isModified());
        $this->assertSame(7, $p->left_id, 'left_id was not overwritten by a lastInsertId fetch');
        $this->assertSame(9, $p->right_id, 'right_id was not overwritten by a lastInsertId fetch');
        $this->assertSame(
            [['left_id' => 7, 'right_id' => 9, 'label' => 'A']],
            $this->rows('SELECT left_id, right_id, label FROM pairing')
        );
    }

    public function testReSavingAnUnmodifiedModelIssuesNoWrites()
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
        $this->em()->save($workshop->markDeleted());

        $this->assertSame(
            [['method' => 'delete', 'table' => 'workshop', 'condition' => ['id = ?' => $id]]],
            $this->db->calls,
            'Exactly one DELETE is issued, scoped by primary key — no incidental UPDATE first'
        );
    }

    public function testDeletedModelDoesNotCascadeToAssignedChildren()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $this->em()->save($workshop);
        $id = $workshop->id;

        // Assign a child and delete the parent in the same save. The relation cascade must be skipped
        // entirely: a deleted parent must not persist its children, whose foreign key is about to vanish.
        $orphan = new Gadget();
        $orphan->name = 'Spanner';
        $workshop->gadgets = [$orphan];

        $this->db->resetCalls();
        $this->em()->save($workshop->markDeleted());

        $this->assertSame(
            [['method' => 'delete', 'table' => 'workshop', 'condition' => ['id = ?' => $id]]],
            $this->db->calls,
            'A deleted model issues only its own DELETE; assigned children are not cascaded'
        );
        $this->assertTrue($orphan->isNew(), 'The assigned child was never persisted');
    }

    public function testDeletedModelDoesNotCascadeToAssignedParent()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $this->em()->save($gadget);
        $id = $gadget->id;

        // Assign a brand-new parent, then delete the gadget. The dependency cascade must be skipped:
        // deleting a model must not persist a relation hung off it as a side effect.
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $gadget->workshop = $workshop;

        $this->db->resetCalls();
        $this->em()->save($gadget->markDeleted());

        $this->assertSame(
            [['method' => 'delete', 'table' => 'gadget', 'condition' => ['id = ?' => $id]]],
            $this->db->calls,
            'A deleted model issues only its own DELETE; an assigned parent is not cascaded'
        );
        $this->assertTrue($workshop->isNew(), 'The assigned parent was never persisted');
    }

    public function testDeletingAModelHonorsAnExplicitlyClearedManyToManyInTheSameSave()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $sticker = new Sticker();
        $sticker->label = 'fragile';
        $gadget->stickers = [$sticker];
        $this->em()->save($gadget);

        $this->assertNotEmpty($this->rows('SELECT * FROM gadget_sticker'), 'precondition: the link exists');

        // Clear the many-to-many and delete its owner in one save. The unset is an explicit request, so the
        // junction must be reconciled to empty even though the owning row is being deleted.
        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->stickers = [];

        $this->em()->save($loaded->markDeleted());

        $this->assertSame([], $this->rows('SELECT * FROM gadget_sticker'), 'The junction was reconciled to empty');
        $this->assertSame([], $this->rows('SELECT * FROM gadget'), 'The owner itself was deleted');
    }

    public function testDeletingAModelSoftDeletesAnExplicitlyClearedSoftJunctionInTheSameSave()
    {
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $tag = new Tag();
        $tag->name = 'sharp';
        $gadget->tags = [$tag];
        $this->em()->save($gadget);

        // Clear a soft-delete junction and delete its owner in one save. The cleared link must be marked
        // deleted (not removed), in the same save as the owner's deletion — the daemon needs that signal.
        /** @var Gadget $loaded */
        $loaded = Gadget::on($this->db)->first();
        $loaded->tags = [];

        $this->em()->save($loaded->markDeleted());

        $this->assertSame(
            [['gadget_id' => $gadget->id, 'tag_id' => $tag->id, 'deleted' => 'y']],
            $this->rows('SELECT gadget_id, tag_id, deleted FROM gadget_tag'),
            'The cleared soft-junction row is marked deleted, not removed'
        );
        $this->assertSame([], $this->rows('SELECT * FROM gadget'), 'The owner itself was deleted');
    }

    public function testDeletingAModelAlsoDeletesAChildExplicitlyMarkedDeleted()
    {
        $workshop = new Workshop();
        $workshop->name = 'Acme';
        $gadget = new Gadget();
        $gadget->name = 'Spanner';
        $workshop->gadgets = [$gadget];
        $this->em()->save($workshop);

        $this->assertNotEmpty($this->rows('SELECT * FROM gadget'), 'precondition: the child exists');

        // Mark a child deleted, assign it to its (also deleted) parent and save the parent. Both deletions
        // are explicit, so both must happen in one save — the child before the parent.
        /** @var Workshop $loadedWorkshop */
        $loadedWorkshop = Workshop::on($this->db)->first();
        /** @var Gadget $loadedGadget */
        $loadedGadget = Gadget::on($this->db)->first();
        $loadedWorkshop->gadgets = [$loadedGadget->markDeleted()];

        $this->em()->save($loadedWorkshop->markDeleted());

        $this->assertSame([], $this->rows('SELECT * FROM gadget'), 'The explicitly deleted child is gone');
        $this->assertSame([], $this->rows('SELECT * FROM workshop'), 'The parent is gone');
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
        $tables = array_column($this->db->calls, 'table');

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
