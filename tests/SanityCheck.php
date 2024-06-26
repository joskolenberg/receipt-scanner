<?php

use HelgeSverre\ReceiptScanner\Data\Receipt;
use HelgeSverre\ReceiptScanner\Facades\ReceiptScanner;
use HelgeSverre\ReceiptScanner\ModelNames;
use HelgeSverre\ReceiptScanner\Prompt;
use HelgeSverre\ReceiptScanner\TextContent;
use HelgeSverre\ReceiptScanner\TextLoader\Textract;
use HelgeSverre\ReceiptScanner\TextLoader\TextractUsingS3Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Completions\CreateResponse as CompletionResponse;

it('validates parsing of receipt data into dto', function () {
    OpenAI::fake([
        ChatResponse::fake([
            'model' => ModelNames::TURBO,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.json'),
                        'function_call' => null,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ]),
    ]);

    $text = file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.txt');
    $result = ReceiptScanner::scan($text, model: ModelNames::TURBO);

    expect($result)->toBeInstanceOf(Receipt::class)
        ->and($result->totalAmount)->toBe(568.00)
        ->and($result->orderRef)->toBe('61e4fb2646c424c5cbc9bc88')
        ->and($result->date->format('Y-m-d'))->toBe('2023-07-21')
        ->and($result->taxAmount)->toBe(74.08)
        ->and($result->currency->value)->toBe('NOK')
        ->and($result->merchant->name)->toBe('Minde Pizzeria')
        ->and($result->merchant->vatId)->toBe('921670362MVA')
        ->and($result->merchant->address)->toBe('Conrad Mohrs veg 5, 5068 Bergen, NOR');

    $expectedResult = json_decode(file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.json'), true);

    foreach ($result->lineItems as $index => $lineItem) {
        expect($lineItem->text)->toBe($expectedResult['lineItems'][$index]['text'], "was '{$lineItem->text}' instead")
            ->and((float) $lineItem->qty)->toBe((float) $expectedResult['lineItems'][$index]['qty'], "was '{$lineItem->qty}' instead")
            ->and($lineItem->price)->toBe($expectedResult['lineItems'][$index]['price'], "was '{$lineItem->price}' instead")
            ->and($lineItem->sku)->toBe($expectedResult['lineItems'][$index]['sku'], "was '{$lineItem->sku}' instead");
    }
});

it('confirms real world usability with GPT4_1106_PREVIEW model', function () {

    $text = file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.txt');
    $result = ReceiptScanner::scan($text, model: ModelNames::GPT4_1106_PREVIEW);

    expect($result)->toBeInstanceOf(Receipt::class)
        ->and($result->totalAmount)->toBe(568.00)
        ->and($result->orderRef)->toBe('61e4fb2646c424c5cbc9bc88')
        ->and($result->date->format('Y-m-d'))->toBe('2023-07-21')
        ->and($result->taxAmount)->toBe(74.08)
        ->and($result->currency->value)->toBe('NOK')
        ->and($result->merchant->name)->toBe('Minde Pizzeria')
        ->and($result->merchant->vatId)->toBe('921670362MVA')
        ->and($result->merchant->address)->toBe('Conrad Mohrs veg 5, 5068 Bergen, NOR');

    $expectedResult = json_decode(file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.json'), true);

    foreach ($result->lineItems as $index => $lineItem) {
        expect($lineItem->text)->toBe($expectedResult['lineItems'][$index]['text'])
            ->and((float) $lineItem->qty)->toBe((float) $expectedResult['lineItems'][$index]['qty'])
            ->and($lineItem->price)->toBe($expectedResult['lineItems'][$index]['price'])
            ->and($lineItem->sku)->toBe($expectedResult['lineItems'][$index]['sku']);
    }
});

it('confirms real world usability with GPT4_TURBO model', function () {

    $text = file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.txt');
    $result = ReceiptScanner::scan($text, model: ModelNames::GPT4_TURBO);

    expect($result)->toBeInstanceOf(Receipt::class)
        ->and($result->totalAmount)->toBe(568.00)
        ->and($result->orderRef)->toBe('61e4fb2646c424c5cbc9bc88')
        ->and($result->date->format('Y-m-d'))->toBe('2023-07-21')
        ->and($result->taxAmount)->toBe(74.08)
        ->and($result->currency->value)->toBe('NOK')
        ->and($result->merchant->name)->toBe('Minde Pizzeria')
        ->and($result->merchant->vatId)->toBe('921670362MVA')
        ->and($result->merchant->address)->toBe('Conrad Mohrs veg 5, 5068 Bergen, NOR');
});

it('confirms real world usability with Turbo Instruct model', function () {

    $text = file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.txt');
    $result = ReceiptScanner::scan($text, model: ModelNames::TURBO_INSTRUCT);

    dump($result->toArray());

    expect($result)->toBeInstanceOf(Receipt::class)
        ->and($result->totalAmount)->toBe(568.00)
        ->and($result->orderRef)->toBe('61e4fb2646c424c5cbc9bc88')
        ->and($result->date->format('Y-m-d'))->toBe('2023-07-21')
        ->and($result->taxAmount)->toBe(74.08)
        ->and($result->currency->value)->toBe('NOK')
        ->and($result->merchant->name)->toContain('Minde Pizzeria')
        ->and($result->merchant->vatId)->toContain('921670362')
        ->and($result->merchant->address)->toContain('Conrad Mohrs veg 5, 5068 Bergen');

    foreach ($result->lineItems as $lineItem) {
        expect($lineItem->toArray())->toHaveKeys(['text', 'qty', 'price', 'sku']);
    }
});

it('validates returning parsed receipt as array', function () {
    OpenAI::fake([
        CompletionResponse::fake([
            'model' => ModelNames::TURBO,
            'choices' => [
                [
                    'text' => file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.json'),
                ],
            ],
        ]),
    ]);

    $text = file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.txt');
    $result = ReceiptScanner::scan($text, model: ModelNames::TURBO_INSTRUCT, asArray: true);

    expect($result)->toBeArray()
        ->and($result['totalAmount'])->toBe(568.00)
        ->and($result['orderRef'])->toBe('61e4fb2646c424c5cbc9bc88')
        ->and($result['date'])->toBe('2023-07-21')
        ->and($result['taxAmount'])->toBe(74.08)
        ->and($result['currency'])->toBe('NOK')
        ->and($result['merchant']['name'])->toBe('Minde Pizzeria')
        ->and($result['merchant']['vatId'])->toBe('921670362MVA')
        ->and($result['merchant']['address'])->toBe('Conrad Mohrs veg 5, 5068 Bergen, NOR');
});

it('confirms real world usability with default model', function () {

    $text = file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.txt');
    $result = ReceiptScanner::scan($text);

    expect($result)->toBeInstanceOf(Receipt::class)
        ->and($result->totalAmount)->toBe(568.00)
        ->and($result->orderRef)->toBe('61e4fb2646c424c5cbc9bc88')
        ->and($result->date->format('Y-m-d'))->toBe('2023-07-21')
        ->and($result->taxAmount)->toBe(74.08)
        ->and($result->currency->value)->toBe('NOK')
        ->and($result->merchant->name)->toBe('Minde Pizzeria')
        ->and($result->merchant->vatId)->toBe('921670362MVA')
        ->and($result->merchant->address)->toBe('Conrad Mohrs veg 5, 5068 Bergen, NOR');

    $expectedResult = json_decode(file_get_contents(__DIR__.'/samples/wolt-pizza-norwegian.json'), true);

    foreach ($result->lineItems as $index => $lineItem) {
        expect(Str::contains($expectedResult['lineItems'][$index]['text'], $lineItem->text))->toBeTrue()
            ->and((float) $lineItem->qty)->toBe((float) $expectedResult['lineItems'][$index]['qty'])
            ->and($lineItem->price)->toBe($expectedResult['lineItems'][$index]['price'])
            ->and($lineItem->sku)->toBe($expectedResult['lineItems'][$index]['sku']);
    }
});

it('validates ocr functionality with image input', function () {
    $image = file_get_contents(__DIR__.'/samples/grocery-receipt-norwegian-spar.jpg');

    $ocr = resolve(Textract::class);
    $text = $ocr->load($image);
    $result = ReceiptScanner::scan($text);
    expect($result)->toBeInstanceOf(Receipt::class)
        ->and($result->totalAmount)->toBe(852.00)
        ->and($result->orderRef)->toBe('66907')
        ->and($result->date->format('Y-m-d'))->toBe('2022-10-30')
        ->and($result->taxAmount)->toBe(109.73)
        ->and($result->currency->value)->toBe('NOK')
        ->and(Str::contains($result->merchant->name, 'SPAR'))->toBeTrue();
});

it('loads prompts and injects context into blade files', function () {
    $prompt = Prompt::load('receipt', ['context' => 'hello world']);
    expect($prompt)->toBeString()
        ->and(Str::contains($prompt, 'hello world'))->toBeTrue();
});

it('can load a pdf via s3', function () {
    $ocr = resolve(TextractUsingS3Upload::class);

    $pdfFile = file_get_contents(__DIR__.'/samples/wolt-food-delivery.pdf');

    $text = $ocr->load($pdfFile);

    expect($text)->toBeInstanceOf(TextContent::class)
        ->and(Str::contains($text, 'Helge Sverre Hessevik Liseth'))->toBeTrue()
        ->and(Str::contains($text, '651852288660ad9ab17636df'))->toBeTrue();

});

it('throws exception when storage operation fails', function () {
    Storage::shouldReceive('disk->put')->andReturn(false);

    $ocr = resolve(TextractUsingS3Upload::class);

    $file = UploadedFile::fake()->create('document.pdf');

    $this->expectException(Exception::class);
    $ocr->load($file);
});
