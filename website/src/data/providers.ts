// Single source for the S3-compatible provider pages. Every fact here was checked against the
// provider's own documentation (see docsUrl). Do NOT add prices, free allowances, speed or
// reliability claims, or comparisons: they change, and tests/providers.test.ts guards against them.
export interface ProviderFaq { q: string; a: string }

export interface Provider {
  slug: string;
  name: string;
  shortName: string;
  endpointPattern: string;
  regionNote: string;
  needs: string[];
  docsUrl: string;
  guideId?: 'cloudflare-r2' | 'backblaze-b2';
  distinct: string[];
  faq: ProviderFaq[];
}

export const providers: Provider[] = [
  {
    slug: 'aws-s3',
    name: 'AWS S3',
    shortName: 'AWS S3',
    endpointPattern: 'https://s3.<region>.amazonaws.com',
    regionNote: 'The region code (for example us-east-1 or eu-central-1) appears in the endpoint and must be the region your bucket lives in.',
    needs: [
      'An AWS account and a bucket in the region you choose',
      'An access key ID and secret access key for an identity that may use that bucket',
      'The region code of the bucket',
    ],
    docsUrl: 'https://docs.aws.amazon.com/general/latest/gr/s3.html',
    distinct: [
      'AWS lists a separate endpoint for every region, each following the s3.<region>.amazonaws.com pattern, so the region code and the endpoint have to agree.',
      'Signature Version 4 is supported in every region, which is the signing method the plugin implements.',
      'Multipart uploads have a documented minimum part size of 5 MB (except the last part) and at most 10,000 parts per upload.',
    ],
    faq: [
      {
        q: 'Which AWS region should I enter?',
        a: 'The region your bucket was created in. The endpoint contains the region code, and a bucket created outside US East (N. Virginia) must be created with its location constraint set to that same region.',
      },
      {
        q: 'Why does the plugin not use the AWS SDK?',
        a: 'It signs its own requests with Signature Version 4 and sends them through the WordPress HTTP API. The same signing code serves every S3-compatible provider.',
      },
    ],
  },
  {
    slug: 'cloudflare-r2',
    name: 'Cloudflare R2',
    shortName: 'R2',
    endpointPattern: 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
    regionNote: 'Use the region value auto. Cloudflare documents that an empty value and us-east-1 are also treated as auto.',
    needs: [
      'A Cloudflare account and an R2 bucket',
      'Your Cloudflare account ID, which forms the endpoint',
      'An R2 API token with Object Read & Write, optionally limited to the bucket, which gives an Access Key ID and Secret Access Key',
    ],
    docsUrl: 'https://developers.cloudflare.com/r2/api/s3/api/',
    guideId: 'cloudflare-r2',
    distinct: [
      'The endpoint is built from your account ID rather than from a region name, and the region is always auto.',
      'Object Read & Write tokens can be limited to specific buckets, so a backup key does not need access to the rest of your account.',
      'Cloudflare shows the Secret Access Key only once, right after the token is created, so it has to be copied then.',
    ],
    faq: [
      {
        q: 'Where do I find the R2 endpoint?',
        a: 'It is https://<ACCOUNT_ID>.r2.cloudflarestorage.com, with your own Cloudflare account ID in place of the placeholder. There is no region in the hostname.',
      },
      {
        q: 'What if I lose the R2 secret?',
        a: 'Cloudflare does not show the Secret Access Key again after the token is created. You would create a new token and enter its keys in the plugin instead.',
      },
    ],
  },
  {
    slug: 'backblaze-b2',
    name: 'Backblaze B2',
    shortName: 'B2',
    endpointPattern: 'https://s3.<region>.backblazeb2.com',
    regionNote: 'The region is part of the hostname (Backblaze documents us-east-005 as an example). Use the S3 endpoint your own bucket shows.',
    needs: [
      'A Backblaze account and a private B2 bucket',
      'An application key, ideally restricted to that bucket, which gives a key ID and an application key',
      "The S3 endpoint for your bucket's region",
    ],
    docsUrl: 'https://www.backblaze.com/docs/cloud-storage-call-the-s3-compatible-api',
    guideId: 'backblaze-b2',
    distinct: [
      'The S3-compatible API accepts HTTPS connections only; plain HTTP is rejected.',
      'Only Signature Version 4 is supported. Version 2 signatures do not work.',
      'Backblaze documents both virtual-hosted style (bucket in the hostname) and path-style (bucket in the path) addressing.',
    ],
    faq: [
      {
        q: 'Which B2 key goes where in the plugin?',
        a: "The plugin takes the B2 application key as the S3 access key and secret pair. Backblaze's own S3 documentation is the reference for how its key ID and application key map onto those two fields.",
      },
      {
        q: 'Can I use plain HTTP with B2?',
        a: 'No. Backblaze documents that its S3-compatible endpoints accept HTTPS connections only, so an http:// endpoint is rejected.',
      },
    ],
  },
  {
    slug: 'wasabi',
    name: 'Wasabi',
    shortName: 'Wasabi',
    endpointPattern: 'https://s3.<region>.wasabisys.com',
    regionNote: 'The region code sits in the hostname. The us-east-1 region also has the shorter s3.wasabisys.com, and some regions have alias hostnames.',
    needs: [
      'A Wasabi account and a bucket in one of its regions',
      'An access key and secret key',
      "The service URL that matches your bucket's region",
    ],
    docsUrl: 'https://docs.wasabi.com/docs/what-are-the-service-urls-for-wasabis-different-storage-regions',
    distinct: [
      'Wasabi publishes a service URL for each storage region, and you must pick the one that matches where your bucket is.',
      'US East 1 is the exception to the pattern: it uses s3.wasabisys.com, with s3.us-east-1.wasabisys.com as an alias.',
      'Several EMEA regions have alias hostnames with country-style codes, for example s3.nl-1.wasabisys.com for EU Central 1 (Amsterdam).',
    ],
    faq: [
      {
        q: 'Which Wasabi endpoint do I use?',
        a: "The service URL for the region your bucket was created in, usually s3.<region>.wasabisys.com. Wasabi's documentation lists the exact URL for each region.",
      },
      {
        q: 'Do I need the Wasabi console URL in the plugin?',
        a: 'No. The console at console.wasabisys.com is only where you manage your account and keys. The plugin talks to the regional service URL.',
      },
    ],
  },
  {
    slug: 'digitalocean-spaces',
    name: 'DigitalOcean Spaces',
    shortName: 'Spaces',
    endpointPattern: 'https://<region>.digitaloceanspaces.com',
    regionNote: 'The Spaces region slug (for example nyc3) is the first part of the hostname, not a separate setting.',
    needs: [
      'A DigitalOcean account and a Space in a Spaces region',
      'A Spaces access key and secret',
      "The endpoint for your Space's region",
    ],
    docsUrl: 'https://docs.digitalocean.com/products/spaces/how-to/use-aws-sdks/',
    distinct: [
      'The region slug leads the hostname, so the endpoint is just https://<region>.digitaloceanspaces.com with no s3. prefix.',
      'DigitalOcean notes that S3 tools may ask for an AWS region name such as us-east-1, which is used only for validation; the real region comes from the endpoint.',
      'Virtual-hosted (subdomain) style requests are supported, and DigitalOcean documents turning path-style off to select them.',
    ],
    faq: [
      {
        q: 'Why do some S3 tools ask for us-east-1 with Spaces?',
        a: 'They need an AWS-style region name to validate the request. DigitalOcean says the actual Spaces region is taken from the endpoint, so the AWS region value does not choose where your data goes.',
      },
      {
        q: 'What does a Spaces endpoint look like?',
        a: 'It is https://<region>.digitaloceanspaces.com with your Space region slug, such as nyc3, in place of <region>.',
      },
    ],
  },
];

export const providerBySlug = (slug: string) => providers.find((p) => p.slug === slug);
