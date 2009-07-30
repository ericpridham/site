<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');

Site::loadClasses(dirname(__FILE__).'/../comp/db.php');
class MyResultClass extends SiteDatabaseResult {}
class MyModelClass extends SiteDatabaseModel {}
class MyWrapClass implements SiteDatabaseRecordWrapper {
  public $row;
  public function __construct($row){ $this->row = $row; }
  public function getRow() { return $this->row; }
}

class SiteDBTest extends PHPUnit_Framework_TestCase {
  protected $backupGlobals = false;

  public function setUp()
  {
    file_put_contents(dirname(__FILE__).'/rw1.yaml', "
components:
  db:
    model: false
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_rw1
"
    );
    file_put_contents(dirname(__FILE__).'/rw2.yaml', "
components:
  db:
    model: false
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_rw2
"
    );
    file_put_contents(dirname(__FILE__).'/ro1.yaml', "
components:
  db:
    model: false
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_ro1
"
    );
    file_put_contents(dirname(__FILE__).'/ro2.yaml', "
components:
  db:
    model: false
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_ro2
"
    );

    file_put_contents(dirname(__FILE__).'/single_db.yaml', "
components:
  db:
    model: false
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_rw1
"
    );

    file_put_contents(dirname(__FILE__).'/pool_db.yaml', "
components:
  db:
    model: false
    pool:
      - host: localhost
        username: sitetest
        password: st123
        database: sitetest_rw1
      - host: localhost
        username: sitetest
        password: st123
        database: sitetest_rw2
"
    );

    file_put_contents(dirname(__FILE__).'/split_db.yaml', "
components:
  db:
    model: false
    rw:
      host: localhost
      username: sitetest
      password: st123
      database: sitetest_rw1
    ro:
      host: localhost
      username: sitetest
      password: st123
      database: sitetest_ro1
"
    );

    file_put_contents(dirname(__FILE__).'/model.yaml', "
components:
  db:
    model: true
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_rw1
"
    );

    file_put_contents(dirname(__FILE__).'/custom_classes.yaml', "
components:
  db:
    model: true
    host: localhost
    username: sitetest
    password: st123
    database: sitetest_rw1
    result_class: MyResultClass
    model_class: MyModelClass
"
    );
  }

  public function testSingleDB()
  {
    $site = new Site(dirname(__FILE__).'/single_db.yaml');

    $ro = $site->db->getConnection('ro');
    $rw = $site->db->getConnection('rw');

    $this->assertSame($ro, $rw);
  }

  public function testDB()
  {
    $site = new Site(dirname(__FILE__).'/single_db.yaml');

    // getFieldInfo
    $info = $site->db->getFieldInfo('st');
    $this->assertType('array', $info);
    $this->assertType('array', $info['pk']);
    $this->assertEquals('1', count($info['pk']));
    $this->assertType('string', $info['auto']);

    $this->assertType('array', $site->db->getFieldInfo('noautoinc', 'pk'));
    $this->assertEquals('1', count($site->db->getFieldInfo('noautoinc', 'pk')));
    $this->assertType('null', $site->db->getFieldInfo('noautoinc', 'auto'));

    $info = $site->db->getFieldInfo('stronly');
    $this->assertType('array', $info);
    $this->assertType('array', $info['pk']);
    $this->assertEquals('0', count($info['pk']));
    $this->assertType('null', $info['auto']);

    // insert
    $id1 = $site->db->insert('st', array('str' => 'row1'));
    $this->assertTrue($id1 > 0);
    $id2 = $site->db->insert('st', array('str' => 'row2'));
    $this->assertTrue($id2 > 0);

    $ret = $site->db->insert('noautoinc', array('pk' => 2, 'str' => 'row1'));
    $this->assertType('boolean', $ret);
    $this->assertEquals(true, $ret);

    // search
    $res = $site->db->search('st', array('id' => $id2));
    $this->assertEquals(1, $res->count());
    $this->assertType('array', $res[0]);
    $this->assertEquals('row2', $res[0]['str']);

    $res = $site->db->search('st', array('id' => -99));
    $this->assertEquals(0, $res->count());
    $this->assertType('null', $res[0]);

    // getFirst
    $res = $site->db->getFirst('noautoinc', array('pk' => 2));
    $this->assertType('array', $res);
    $this->assertEquals('row1', $res['str']);

    // update
    $site->db->update('st', array('id' => $id1), array('str' => 'row1-updated'));
    $res = $site->db->getFirst('st', array('id' => $id1));
    $this->assertEquals('row1-updated', $res['str']);

    // delete
    $site->db->delete('st', array('id' => $id2));
    $res = $site->db->getFirst('st', array('id' => $id2));
    $this->assertType('null', $res);

    // transactions
    $site->db->beginTransaction();
    $id3 = $site->db->insert('st', array('str' => 'row3'));
    $res = $site->db->getFirst('st', array('id' => $id3));
    $this->assertType('array', $res);
    $site->db->rollback();
    $res = $site->db->getFirst('st', array('id' => $id3));
    $this->assertType('null', $res);

    $site->db->beginTransaction();
    $id3 = $site->db->insert('st', array('str' => 'row3'));
    $res = $site->db->getFirst('st', array('id' => $id3));
    $this->assertType('array', $res);
    $site->db->commit();
    $res = $site->db->getFirst('st', array('id' => $id3));
    $this->assertType('array', $res);

    //print_r($site->log->get());
    //echo $site->db->getDebugOutput();
  }

  public function testResult()
  {
    $site = new Site(dirname(__FILE__).'/single_db.yaml');

    $id1 = $site->db->insert('st', array('str' => 'row1'));
    $id2 = $site->db->insert('st', array('str' => 'row2'));
    $id3 = $site->db->insert('st', array('str' => 'row3'));
    $id4 = $site->db->insert('st', array('str' => 'row4'));
    $id5 = $site->db->insert('st', array('str' => 'row5'));


    //
    // straight select all
    //
    $res = $site->db->query('select * from st order by id');
    $this->assertType('SiteDatabaseResult', $res);
    $this->assertEquals(5, $res->count());

    // index into middle of result set, make sure only fetches rows needed
    $this->assertEquals('row3', $res[2]['str']);
    $this->assertEquals(3, $res->inspect('fetch_pos'));
    $this->assertEquals(3, count($res->inspect('rows')));
    $this->assertEquals(false, $res->inspect('fetched'));

    // testing wrap class
    $this->assertType('array', $res[2]);
    $res->setWrapClass('MyWrapClass');
    $this->assertType('MyWrapClass', $res[2]);
    $res->setWrapClass(null);
    $this->assertType('array', $res[2]);

    $fr = $res->fetchRows();
    $this->assertType('array', $fr[2]);
    $res->setWrapClass('MyWrapClass');
    $fr = $res->fetchRows();
    $this->assertType('MyWrapClass', $fr[2]);
    $res->setWrapClass(null);
    $fr = $res->fetchRows();
    $this->assertType('array', $fr[2]);

    // foreach should loop through all even if fetched half way through
    $i = 0;
    foreach ($res as $x => $row) {
      $this->assertEquals($i++, $x);
    }
    $this->assertEquals(5, $i);
    // ... and should complete the fetch
    $this->assertEquals(5, $res->inspect('fetch_pos'));
    $this->assertEquals(5, count($res->inspect('rows')));
    $this->assertEquals(true, $res->inspect('fetched'));


    //
    // select with count limit
    //
    $res = $site->db->query('select * from st order by id', null, 3);
    $this->assertEquals(3, $res->count());

    $this->assertEquals('row3', $res[2]['str']);
    $this->assertEquals(3, $res->inspect('fetch_pos'));
    $this->assertEquals(3, count($res->inspect('rows')));
    $this->assertEquals(true, $res->inspect('fetched'));


    $res = $site->db->query('select * from st order by id', null, 3, 3);
    $this->assertEquals(3, $res->count());

    $this->assertEquals('row3', $res[0]['str']);
    $this->assertEquals(1, $res->inspect('fetch_pos'));
    $this->assertEquals(1, count($res->inspect('rows')));
    $this->assertEquals(false, $res->inspect('fetched'));


    //
    // select with start index
    //
    $res = $site->db->query('select * from st order by id', null, null, 3);
    $this->assertEquals(3, $res->count());

    $this->assertEquals('row3', $res[0]['str']);
    $this->assertEquals(1, $res->inspect('fetch_pos'));
    $this->assertEquals(1, count($res->inspect('rows')));
    $this->assertEquals(false, $res->inspect('fetched'));


    //
    // select with out-of-bounds count limit
    //
    $res = $site->db->query('select * from st order by id', null, 999);
    $this->assertEquals(5, $res->count());

    $this->assertEquals('row1', $res[0]['str']);
    $this->assertEquals(1, $res->inspect('fetch_pos'));
    $this->assertEquals(1, count($res->inspect('rows')));
    $this->assertEquals(false, $res->inspect('fetched'));


    //
    // select with count limit and start index
    //
    $res = $site->db->query('select * from st order by id', null, 3, 3);
    $this->assertEquals(3, $res->count());

    $this->assertEquals('row3', $res[0]['str']);
    $this->assertEquals(1, $res->inspect('fetch_pos'));
    $this->assertEquals(1, count($res->inspect('rows')));
    $this->assertEquals(false, $res->inspect('fetched'));


    //
    // select with start index and out-of-bounds count limit
    //
    $res = $site->db->query('select * from st order by id', null, 999, 3);
    $this->assertEquals(3, $res->count());

    $this->assertEquals('row3', $res[0]['str']);
    $this->assertEquals(1, $res->inspect('fetch_pos'));
    $this->assertEquals(1, count($res->inspect('rows')));
    $this->assertEquals(false, $res->inspect('fetched'));


    //
    // select with out-of-bounds start index
    //
    $res = $site->db->query('select * from st order by id', null, null, 999);
    $this->assertEquals(0, $res->count());

    $this->assertType('null', $res[0]);
    $this->assertEquals(0, count($res->inspect('rows')));
    $this->assertEquals(true, $res->inspect('fetched'));


    //
    // select with count limit and out-of-bounds start index
    //
    $res = $site->db->query('select * from st order by id', null, 3, 999);
    $this->assertEquals(0, $res->count());

    $this->assertType('null', $res[0]);
    $this->assertEquals(0, $res->inspect('fetch_pos'));
    $this->assertEquals(0, count($res->inspect('rows')));
    $this->assertEquals(true, $res->inspect('fetched'));


    //
    // initial foreach
    //
    $res = $site->db->query('select * from st order by id');
    $i = 0;
    foreach ($res as $row) {
      if (!++$i) {
        // looks like foreach fetches entire set before starting loop
        $this->assertEquals(5, count($res->inspect('rows')));
        $this->assertEquals(5, $res->inspect('fetch_pos'));
      }

      $this->assertEquals("row$i", $row['str']);
    }

    //print_r($site->log->get());
    //echo $site->db->getDebugOutput();
  }

  public function testModel()
  {
    $site = new Site(dirname(__FILE__).'/model.yaml');

    // __get
    $this->assertType('SiteDatabaseModel', $site->db->_model);

    // getFieldInfo
    $info = $site->db->_model->getFieldInfo('st');
    $this->assertType('array', $info);
    $this->assertType('array', $info['pk']);
    $this->assertEquals('1', count($info['pk']));
    $this->assertType('string', $site->db->_model->getFieldInfo('st', 'auto'));

    // create
    $rec = $site->db->_model->create('st');
    $this->assertType('SiteDatabaseModelRecord', $rec);
    $this->assertType('array', $rec->getRow());
    $this->assertEquals(0, count($rec->getRow()));
    $this->assertEquals(false, $rec->inspect('exists'));
    $this->assertEquals(false, $rec->inspect('dirty'));

    $rec = $site->db->_model->create('st', array('str' => 'foo'));
    $this->assertEquals(1, count($rec->getRow()));

    $rec = $site->db->_model->create('st', null, true);
    $this->assertEquals(true, $rec->inspect('exists'));
    $this->assertEquals(false, $rec->inspect('dirty'));

    $rec = $site->db->_model->create('st', null, true, true);
    $this->assertEquals(true, $rec->inspect('exists'));
    $this->assertEquals(true, $rec->inspect('dirty'));

    // getRecordClass
    $this->assertEquals('SiteDatabaseModelRecord', $site->db->_model->getRecordClass('st'));

    // inserting in tested way
    $id1 = $site->db->insert('st', array('str' => 'row1'));
    $id2 = $site->db->insert('st', array('str' => 'row2'));

    // get
    $rec = $site->db->_model->get('st', array('id' => $id1));
    $this->assertType('SiteDatabaseModelRecord', $rec);
    $rec = $site->db->_model->get('st', array('id' => -99));
    $this->assertType('null', $rec);

    // all
    $res = $site->db->_model->all('stronly');
    $this->assertEquals(0, $res->count());
    $this->assertType('null', $res[0]);

    $res = $site->db->_model->all('st');
    $this->assertEquals(2, $res->count());
    $this->assertType('SiteDatabaseModelRecord', $res[0]);

    // search
    $res = $site->db->_model->search('st', array('str' => 'row2'));
    $this->assertEquals(1, $res->count());
    $this->assertType('SiteDatabaseModelRecord', $res[0]);

    $res = $site->db->_model->search('st', null, 'str desc');
    $this->assertEquals(2, $res->count());
    $x = $res[1]->getRow();
    $this->assertType('array', $x);
    $this->assertEquals('row1', $x['str']);

    //query
    $res = $site->db->_model->query('st', 'str <> :str', array('str' => 'row2'));
    $this->assertEquals(1, $res->count());
    $this->assertType('SiteDatabaseModelRecord', $res[0]);
    $x = $res[0]->getRow();
    $this->assertEquals('row1', $x['str']);

    // insert
    $rec = $site->db->_model->insert('st', array('str' => 'row3'));
    $this->assertType('SiteDatabaseModelRecord', $rec);
    $x = $rec->getRow();
    $this->assertEquals('row3', $x['str']);

    // update
    $rec = $site->db->_model->update('st', array('id' => $rec->id), array('str' => 'row3-updated'));
    $this->assertType('SiteDatabaseModelRecord', $rec);
    $x = $rec->getRow();
    $this->assertEquals('row3-updated', $x['str']);

    // delete
  }

  public function testModelTable()
  {
    $site = new Site(dirname(__FILE__).'/model.yaml');

    // __get
    $this->assertType('SiteDatabaseModelTable', $site->db->st);

    // getFieldInfo
    $info = $site->db->st->getFieldInfo();
    $this->assertType('array', $info);
    $this->assertType('array', $info['pk']);
    $this->assertEquals('1', count($info['pk']));
    $this->assertType('string', $site->db->st->getFieldInfo('auto'));

    // insert
    $rec = $site->db->st->insert(array('str' => 'row2'));
    $this->assertType('SiteDatabaseModelRecord', $rec);
  }

  public function testModelRecord()
  {
    $site = new Site(dirname(__FILE__).'/model.yaml');

    $rec = $site->db->st->insert(array('str' => 'row1'));

    $this->assertEquals(true, $rec->inspect('exists'));
    $this->assertEquals(true, $rec->inspect('dirty'));
    $this->assertEquals('row1', $rec->str);
    $this->assertEquals(false, $rec->inspect('dirty'));
  }

  public function testCustomClasses()
  {
    $site = new Site(dirname(__FILE__).'/custom_classes.yaml');

    $id1 = $site->db->insert('st', array('str' => 'row1'));
    $res = $site->db->search('st', array('id' => $id1));

    $this->assertType('MyModelClass', $site->db->_model);
    $this->assertType('MyResultClass', $res);
  }

  public function testPoolDB()
  {
    $site = new Site(dirname(__FILE__).'/pool_db.yaml');

    $ro = $site->db->getConnection('ro');
    $rw = $site->db->getConnection('rw');

    $this->assertSame($ro, $rw);
  }

  public function testSplitDB()
  {
    $rw1 = new Site(dirname(__FILE__).'/rw1.yaml');
    $rw1id = $rw1->db->insert('st', array('str'=>'rw1'));
    $ro1 = new Site(dirname(__FILE__).'/ro1.yaml');
    $ro1id = $ro1->db->insert('st', array('str'=>'ro1'));

    $site = new Site(dirname(__FILE__).'/split_db.yaml');

    $rores = $site->db->queryRO('select * from st');
    $this->assertEquals(1, $rores->count());
    $this->assertEquals('ro1', $rores[0]['str']);

    $rwres = $site->db->queryRW('select * from st');
    $this->assertEquals(1, $rwres->count());
    $this->assertEquals('rw1', $rwres[0]['str']);

    // NOTE: subsequent calls after a RW query always hit RW
    $rores = $site->db->queryRO('select * from st');
    $this->assertEquals(1, $rores->count());
    $this->assertEquals('rw1', $rores[0]['str']);
  }

  public function tearDown()
  {
    $site = new Site(dirname(__FILE__).'/rw1.yaml');
    $site->db->queryRW('truncate table st');
    $site->db->queryRW('truncate table noautoinc');
    $site = new Site(dirname(__FILE__).'/rw2.yaml');
    $site->db->queryRW('truncate table st');
    $site = new Site(dirname(__FILE__).'/ro1.yaml');
    $site->db->queryRW('truncate table st');
    $site = new Site(dirname(__FILE__).'/ro2.yaml');
    $site->db->queryRW('truncate table st');

    unlink(dirname(__FILE__).'/rw1.yaml');
    unlink(dirname(__FILE__).'/rw2.yaml');
    unlink(dirname(__FILE__).'/ro1.yaml');
    unlink(dirname(__FILE__).'/ro2.yaml');
    unlink(dirname(__FILE__).'/single_db.yaml');
    unlink(dirname(__FILE__).'/pool_db.yaml');
    unlink(dirname(__FILE__).'/split_db.yaml');
    unlink(dirname(__FILE__).'/model.yaml');
    unlink(dirname(__FILE__).'/custom_classes.yaml');
  }
}
?>
