# Vehicle catalog seed and verification

The customer catalog is limited to Honda, Yamaha, Suzuki, and factory Vespa automatic scooters. A model family can exist before exact year and trim data are known, but a customer cannot register it until an active generation, year, and applicable trim are present. Records marked `needs_verification` are labeled as such and are never promoted by a customer request.

## Seed baseline

The seeder includes 18 Honda model families, 20 Yamaha families, 16 Suzuki families, and six Vespa automatic families. These family entries are marked `needs_verification`; their links point to manufacturer product indexes and are discovery references only. The seed does not assert production years, generations, factory trims, or technical specifications for those families until a model-specific source is recorded.

The verified year/trim baseline is the 2018 Yamaha FreeGo 125 launch generation:

- Standard: ABS no; Smart Key/keyless no.
- S: ABS no; Smart Key/keyless yes.
- S ABS: ABS yes; Smart Key/keyless yes.

ABS and keyless are stored as separate attributes. Yamaha Indonesia's 31 October 2018 launch announcement states three trims, identifies Smart Key on S and S-ABS, identifies ABS on S-ABS, and lists 124.96 cc, fuel injection, and full automatic transmission: [FreeGo, The Amazing Matic](https://www.yamaha-motor.co.id/archives/news/corporate/2018/10/freego-the-amazing-matic/). The source does not establish later model-year applicability, so only 2018 is seeded as verified.

## Manufacturer discovery references

These indexes help administrators find current product families. They are not sufficient evidence for discontinued model years or trim compatibility by themselves.

- Honda: <https://www.astra-honda.com/product>
- Yamaha: <https://www.yamaha-motor.co.id/product/>
- Suzuki: <https://www.suzuki.co.id/motorcycle>
- Vespa Indonesia: <https://www.vespa.com/id_ID/>

## Parts fitment baseline

No cross-model part fitments are seeded as confirmed because no exact shared OEM part number or manufacturer/aftermarket application catalog was verified in this refinement. Administrators must record source type, publisher, reference, part number when available, optional trim/year bounds, verifier, and verification time before a fitment is shown as confirmed. Mechanic-confirmed fitments remain labeled by that source type.
