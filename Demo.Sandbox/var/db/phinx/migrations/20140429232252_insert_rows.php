<?php

use Phinx\Migration\AbstractMigration;

class InsertRows extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     *
     * Uncomment this method if you would like to use it.
     *
     */
    public function change()
    {
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Perspective\", \"Perspective is worth 80 IQ points.\n\n--Alan Kay\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Before it becomes normal\", \"Quite a few people have to believe something is normal before it becomes normal - a sort of 'voting' situation. But once the threshold is reached, then everyone demands to do whatever it is.\n\n--Alan Kay\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Most software today\", \"Most software today is very much like an Egyptian pyramid with millions of bricks piled on top of each other, with no structural integrity, but just done by brute force and thousands of slaves.\n\n--Alan Kay\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Make an apple pie from scratch\", \"If you wish to make an apple pie from scratch, you must first invent the universe.\n\n--Carl Sagan\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Computers\", \"I do not fear computers. I fear the lack of them.\nRead more at\n\n--Issac Asimov\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Accept yourself\", \"Accept yourself as you are. Otherwise you will never see opportunity. You will not feel free to move toward it; you will feel you are not deserving.\n\n--Maxwell Maltz\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"Not being tense but ready\", \"Not thinking but not dreaming.\nNot being set but flexible.\nLiberation from the uneasy sense of confinement.\nIt is being wholly and quietly alive, aware and alert, ready for whatever may come.\n\n--Bruce Lee\")"
        );
        $this->execute(
            "INSERT INTO posts (title, body) "
            . "VALUES(\"In the beginner's mind\", \"In the beginner's mind there are many possibilities, but in the expert’s there are few\n\n-- Shunryu Suzuki, Zen Mind, Beginner's Mind\")"
        );
    }

    /**
     * Migrate Up.
     */
    public function up()
    {

    }

    /**
     * Migrate Down.
     */
    public function down()
    {

    }
}
