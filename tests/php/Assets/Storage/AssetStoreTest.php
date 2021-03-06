<?php

namespace SilverStripe\Assets\Tests\Storage;

use Exception;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Tests\Storage\AssetStoreTest\TestAssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class AssetStoreTest extends SapphireTest
{

    /**
     * @skipUpgrade
     */
    public function setUp()
    {
        parent::setUp();

        // Set backend and base url
        TestAssetStore::activate('AssetStoreTest');
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * @return TestAssetStore
     */
    protected function getBackend()
    {
        return Injector::inst()->get('AssetStore');
    }

    /**
     * Test different storage methods
     */
    public function testStorageMethods()
    {
        $backend = $this->getBackend();

        // Test setFromContent
        $puppies1 = 'puppies';
        $puppies1Tuple = $backend->setFromString($puppies1, 'pets/my-puppy.txt');
        $this->assertEquals(
            array(
                'Hash' => '2a17a9cb4be918774e73ba83bd1c1e7d000fdd53',
                'Filename' => 'pets/my-puppy.txt',
                'Variant' => '',
            ),
            $puppies1Tuple
        );

        // Test setFromStream (seekable)
        $fish1 = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-high-quality.jpg');
        $fish1Stream = fopen($fish1, 'r');
        $fish1Tuple = $backend->setFromStream($fish1Stream, 'parent/awesome-fish.jpg');
        fclose($fish1Stream);
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'parent/awesome-fish.jpg',
                'Variant' => '',
            ),
            $fish1Tuple
        );

        // Test with non-seekable streams
        TestAssetStore::$seekable_override = false;
        $fish2 = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-low-quality.jpg');
        $fish2Stream = fopen($fish2, 'r');
        $fish2Tuple = $backend->setFromStream($fish2Stream, 'parent/mediocre-fish.jpg');
        fclose($fish2Stream);

        $this->assertEquals(
            array(
                'Hash' => '33be1b95cba0358fe54e8b13532162d52f97421c',
                'Filename' => 'parent/mediocre-fish.jpg',
                'Variant' => '',
            ),
            $fish2Tuple
        );
        TestAssetStore::$seekable_override = null;
    }

    /**
     * Test that the backend correctly resolves conflicts
     */
    public function testConflictResolution()
    {
        $backend = $this->getBackend();

        // Put a file in
        $fish1 = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish1);
        $fish1Tuple = $backend->setFromLocalFile($fish1, 'directory/lovely-fish.jpg');
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ),
            $fish1Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fish1Tuple['Filename'], $fish1Tuple['Hash'])
        );

        // Write a different file with same name. Should not detect duplicates since sha are different
        $fish2 = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-low-quality.jpg');
        try {
            $fish2Tuple = $backend->setFromLocalFile(
                $fish2,
                'directory/lovely-fish.jpg',
                null,
                null,
                array('conflict' => AssetStore::CONFLICT_EXCEPTION)
            );
        } catch (Exception $ex) {
            $this->fail('Writing file with different sha to same location failed with exception');
            return;
        }
        $this->assertEquals(
            array(
                'Hash' => '33be1b95cba0358fe54e8b13532162d52f97421c',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ),
            $fish2Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/33be1b95cb/lovely-fish.jpg',
            $backend->getAsURL($fish2Tuple['Filename'], $fish2Tuple['Hash'])
        );

        // Write original file back with rename
        $this->assertFileExists($fish1);
        $fish3Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_RENAME)
        );
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish-v2.jpg',
                'Variant' => '',
            ),
            $fish3Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/a870de278b/lovely-fish-v2.jpg',
            $backend->getAsURL($fish3Tuple['Filename'], $fish3Tuple['Hash'])
        );

        // Write another file should increment to -v3
        $fish4Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish-v2.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_RENAME)
        );
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish-v3.jpg',
                'Variant' => '',
            ),
            $fish4Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/a870de278b/lovely-fish-v3.jpg',
            $backend->getAsURL($fish4Tuple['Filename'], $fish4Tuple['Hash'])
        );

        // Test conflict use existing file
        $fish5Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_USE_EXISTING)
        );
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ),
            $fish5Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fish5Tuple['Filename'], $fish5Tuple['Hash'])
        );

        // Test conflict use existing file
        $fish6Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_OVERWRITE)
        );
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ),
            $fish6Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fish6Tuple['Filename'], $fish6Tuple['Hash'])
        );
    }

    /**
     * Test that flysystem can regenerate the original filename from fileID
     */
    public function testGetOriginalFilename()
    {
        $store = new TestAssetStore();
        $this->assertEquals(
            'directory/lovely-fish.jpg',
            $store->getOriginalFilename('directory/a870de278b/lovely-fish.jpg')
        );
        $this->assertEquals(
            'directory/lovely-fish.jpg',
            $store->getOriginalFilename('directory/a870de278b/lovely-fish__variant.jpg')
        );
        $this->assertEquals(
            'directory/lovely_fish.jpg',
            $store->getOriginalFilename('directory/a870de278b/lovely_fish__vari_ant.jpg')
        );
        $this->assertEquals(
            'directory/lovely_fish.jpg',
            $store->getOriginalFilename('directory/a870de278b/lovely_fish.jpg')
        );
        $this->assertEquals(
            'lovely-fish.jpg',
            $store->getOriginalFilename('a870de278b/lovely-fish.jpg')
        );
        $this->assertEquals(
            'lovely-fish.jpg',
            $store->getOriginalFilename('a870de278b/lovely-fish__variant.jpg')
        );
        $this->assertEquals(
            'lovely_fish.jpg',
            $store->getOriginalFilename('a870de278b/lovely_fish__vari__ant.jpg')
        );
        $this->assertEquals(
            'lovely_fish.jpg',
            $store->getOriginalFilename('a870de278b/lovely_fish.jpg')
        );
    }

    /**
     * Test internal file Id generation
     */
    public function testGetFileID()
    {
        $store = new TestAssetStore();
        $this->assertEquals(
            'directory/2a17a9cb4b/file.jpg',
            $store->getFileID('directory/file.jpg', sha1('puppies'))
        );
        $this->assertEquals(
            '2a17a9cb4b/file.jpg',
            $store->getFileID('file.jpg', sha1('puppies'))
        );
        $this->assertEquals(
            'dir_ectory/2a17a9cb4b/fil_e.jpg',
            $store->getFileID('dir__ectory/fil__e.jpg', sha1('puppies'))
        );
        $this->assertEquals(
            'directory/2a17a9cb4b/file_variant.jpg',
            $store->getFileID('directory/file__variant.jpg', sha1('puppies'), null)
        );
        $this->assertEquals(
            'directory/2a17a9cb4b/file__variant.jpg',
            $store->getFileID('directory/file.jpg', sha1('puppies'), 'variant')
        );
        $this->assertEquals(
            '2a17a9cb4b/file__var__iant.jpg',
            $store->getFileID('file.jpg', sha1('puppies'), 'var__iant')
        );
    }

    public function testGetMetadata()
    {
        $backend = $this->getBackend();

        // jpg
        $fish = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-high-quality.jpg');
        $fishTuple = $backend->setFromLocalFile($fish, 'parent/awesome-fish.jpg');
        $this->assertEquals(
            'image/jpeg',
            $backend->getMimeType($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $fishMeta = $backend->getMetadata($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertEquals(151889, $fishMeta['size']);
        $this->assertEquals('file', $fishMeta['type']);
        $this->assertNotEmpty($fishMeta['timestamp']);

        // text
        $puppies = 'puppies';
        $puppiesTuple = $backend->setFromString($puppies, 'pets/my-puppy.txt');
        $this->assertEquals(
            'text/plain',
            $backend->getMimeType($puppiesTuple['Filename'], $puppiesTuple['Hash'])
        );
        $puppiesMeta = $backend->getMetadata($puppiesTuple['Filename'], $puppiesTuple['Hash']);
        $this->assertEquals(7, $puppiesMeta['size']);
        $this->assertEquals('file', $puppiesMeta['type']);
        $this->assertNotEmpty($puppiesMeta['timestamp']);
    }

    /**
     * Test that legacy filenames work as expected
     */
    public function testLegacyFilenames()
    {
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', true);

        $backend = $this->getBackend();

        // Put a file in
        $fish1 = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish1);
        $fish1Tuple = $backend->setFromLocalFile($fish1, 'directory/lovely-fish.jpg');
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ),
            $fish1Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/lovely-fish.jpg',
            $backend->getAsURL($fish1Tuple['Filename'], $fish1Tuple['Hash'])
        );

        // Write a different file with same name.
        // Since we are using legacy filenames, this should generate a new filename
        $fish2 = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-low-quality.jpg');
        try {
            $backend->setFromLocalFile(
                $fish2,
                'directory/lovely-fish.jpg',
                null,
                null,
                array('conflict' => AssetStore::CONFLICT_EXCEPTION)
            );
            $this->fail('Writing file with different sha to same location should throw exception');
            return;
        } catch (Exception $ex) {
            // Success
        }

        // Re-attempt this file write with conflict_rename
        $fish3Tuple = $backend->setFromLocalFile(
            $fish2,
            'directory/lovely-fish.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_RENAME)
        );
        $this->assertEquals(
            array(
                'Hash' => '33be1b95cba0358fe54e8b13532162d52f97421c',
                'Filename' => 'directory/lovely-fish-v2.jpg',
                'Variant' => '',
            ),
            $fish3Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/lovely-fish-v2.jpg',
            $backend->getAsURL($fish3Tuple['Filename'], $fish3Tuple['Hash'])
        );

        // Write back original file, but with CONFLICT_EXISTING. The file should not change
        $fish4Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish-v2.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_USE_EXISTING)
        );
        $this->assertEquals(
            array(
                'Hash' => '33be1b95cba0358fe54e8b13532162d52f97421c',
                'Filename' => 'directory/lovely-fish-v2.jpg',
                'Variant' => '',
            ),
            $fish4Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/lovely-fish-v2.jpg',
            $backend->getAsURL($fish4Tuple['Filename'], $fish4Tuple['Hash'])
        );

        // Write back original file with CONFLICT_OVERWRITE. The file sha should now be updated
        $fish5Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish-v2.jpg',
            null,
            null,
            array('conflict' => AssetStore::CONFLICT_OVERWRITE)
        );
        $this->assertEquals(
            array(
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish-v2.jpg',
                'Variant' => '',
            ),
            $fish5Tuple
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/directory/lovely-fish-v2.jpg',
            $backend->getAsURL($fish5Tuple['Filename'], $fish5Tuple['Hash'])
        );
    }

    /**
     * Test default conflict resolution
     */
    public function testDefaultConflictResolution()
    {
        $store = $this->getBackend();

        // Disable legacy filenames
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', false);
        $this->assertEquals(AssetStore::CONFLICT_OVERWRITE, $store->getDefaultConflictResolution(null));
        $this->assertEquals(AssetStore::CONFLICT_OVERWRITE, $store->getDefaultConflictResolution('somevariant'));

        // Enable legacy filenames
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', true);
        $this->assertEquals(AssetStore::CONFLICT_RENAME, $store->getDefaultConflictResolution(null));
        $this->assertEquals(AssetStore::CONFLICT_OVERWRITE, $store->getDefaultConflictResolution('somevariant'));
    }

    /**
     * Test protect / publish mechanisms
     */
    public function testProtect()
    {
        $backend = $this->getBackend();
        $fish = realpath(__DIR__ . '/../../ORM/ImageTest/test-image-high-quality.jpg');
        $fishTuple = $backend->setFromLocalFile($fish, 'parent/lovely-fish.jpg');
        $fishVariantTuple = $backend->setFromLocalFile($fish, $fishTuple['Filename'], $fishTuple['Hash'], 'copy');

        // Test public file storage
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $backend->getVisibility($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/parent/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/parent/a870de278b/lovely-fish__copy.jpg',
            $backend->getAsURL($fishVariantTuple['Filename'], $fishVariantTuple['Hash'], $fishVariantTuple['Variant'])
        );

        // Test access rights to public files cannot be revoked
        $backend->revoke($fishTuple['Filename'], $fishTuple['Hash']); // can't revoke public assets
        $this->assertTrue($backend->canView($fishTuple['Filename'], $fishTuple['Hash']));

        // Test protected file storage
        $backend->protect($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertFileNotExists(ASSETS_PATH . '/AssetStoreTest/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileNotExists(ASSETS_PATH . '/AssetStoreTest/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertEquals(
            AssetStore::VISIBILITY_PROTECTED,
            $backend->getVisibility($fishTuple['Filename'], $fishTuple['Hash'])
        );

        // Test access rights
        $backend->revoke($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertFalse($backend->canView($fishTuple['Filename'], $fishTuple['Hash']));
        $backend->grant($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertTrue($backend->canView($fishTuple['Filename'], $fishTuple['Hash']));

        // Protected urls should go through asset routing mechanism
        $this->assertEquals(
            '/assets/parent/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/assets/parent/a870de278b/lovely-fish__copy.jpg',
            $backend->getAsURL($fishVariantTuple['Filename'], $fishVariantTuple['Hash'], $fishVariantTuple['Variant'])
        );

        // Publish reverts visibility
        $backend->publish($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertFileNotExists(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileNotExists(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $backend->getVisibility($fishTuple['Filename'], $fishTuple['Hash'])
        );
    }
}
