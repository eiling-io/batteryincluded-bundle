<?php

namespace App\Provider;

use Batteryincluded\BatteryincludedBundle\Provider\DataProvider;
use BatteryIncludedSdk\Dto\BlogBaseDto;
use BatteryIncludedSdk\Dto\CategoryDto;
use BatteryIncludedSdk\Dto\ProductBaseDto;
use BatteryIncludedSdk\Dto\ProductPropertyDto;
use Generator;

class BlogProvider implements DataProvider
{
    public function getBatches(int $batchSize): Generator
    {
        $blogs = $this->generateBlogs(100);
        $batches = array_chunk($blogs, $batchSize);

        foreach ($batches as $skuBatch) {
            yield $skuBatch;
        }
    }

    public function generateBlogs(int $int): array
    {
        $blogs = [];
        for ($i = 1; $i <= $int; $i++) {
            $blog = new BlogBaseDto((string) $i, 'BLOG');
            $blog->setTitle('Blog Post ' . $i);
            $blog->setDescription('This is the content of blog post number ' . $i . '.');
            $blog->setAuthor('Author ' . $i);
            $blog->setPreviewImage('https://dummyimage.com/600x400/bbb/fff.png&text=Blog ' . $i);
            $blog->setPublishedAt((new \DateTime())->modify('-' . (30 - $i) . ' days')->format('Y-m-d'));
            $blogs[] = $blog;
        }

        return $blogs;
    }
}