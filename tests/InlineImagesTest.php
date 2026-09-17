<?php

namespace ToxicFilter\Tests;

/**
 * A picture the caller holds.
 *
 * `/v1/image` takes an address OR bytes, exactly one, and for a long time this client only
 * ever wrote `url` while its own README documented otherwise. The rule is not a heuristic:
 * `url` accepts http and https and nothing else, so anything else is bytes by elimination.
 */
final class InlineImagesTest extends TestCase
{
    public function test_bytes_go_out_as_data(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $tf->imageData("\x89PNG\r\n\x1a\n", ['reference' => 'avatar_9']);

        $body = $transport->calls[0]['body'];

        $this->assertSame('https://example.test/api/v1/image', $transport->calls[0]['url']);
        $this->assertSame('iVBORw0KGgo=', $body['data']);
        $this->assertArrayNotHasKey('url', $body);
        $this->assertSame('avatar_9', $body['reference']);
    }

    public function test_a_data_uri_handed_to_image_goes_out_as_bytes(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $tf->image('data:image/png;base64,iVBORw0KGgo=');

        $body = $transport->calls[0]['body'];

        // As `url` it would be refused, and the caller would have no idea why: it is a URI,
        // it just is not an address.
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $body['data']);
        $this->assertArrayNotHasKey('url', $body);
    }

    public function test_bare_base64_handed_to_image_also_goes_out_as_bytes(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $tf->image('iVBORw0KGgo=');

        $this->assertSame('iVBORw0KGgo=', $transport->calls[0]['body']['data']);
        $this->assertArrayNotHasKey('url', $transport->calls[0]['body']);
    }

    public function test_an_already_encoded_string_is_not_encoded_twice(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $tf->imageData('iVBORw0KGgo=');

        // Encoding it again sends the alphabet of the alphabet, and the service decodes one
        // layer and finds text where a picture should be.
        $this->assertSame('iVBORw0KGgo=', $transport->calls[0]['body']['data']);
    }

    public function test_an_address_still_goes_out_as_a_url(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        $tf->image('https://cdn.example.test/a.jpg');

        $body = $transport->calls[0]['body'];

        $this->assertSame('https://cdn.example.test/a.jpg', $body['url']);
        $this->assertArrayNotHasKey('data', $body);
    }

    public function test_a_real_file_survives_the_round_trip(): void
    {
        [$tf, $transport] = $this->client([[200, $this->verdict()]]);

        // A one pixel GIF, as raw bytes: the case the strict base64 check has to get right,
        // since a binary file can begin with characters that look like an encoding.
        $bytes = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);

        $tf->imageData($bytes);

        $this->assertSame($bytes, base64_decode($transport->calls[0]['body']['data'], true));
    }
}
