# Screenshots without call-outs

The same pictures as [`../images/`](../images/), with the same folder and file names, but **without the numbered
red call-outs and frames** the user guide draws on top of them. They are meant for reuse outside the guide, for
example on a web site or in slides.

- **One folder per page group** (`service-desk/`, `billing/`, `portal/`, ...), files named exactly as in
  `../images/`. A picture in the guide at `images/billing/03-invoice-list.png` is `images-clean/billing/03-invoice-list.png` here.
- **Same capture, one step skipped.** They come from the same demo company and the same capture scripts as the
  guide's pictures; the only difference is that the call-out step is skipped. Crops, window sizes and data match.
- **Lossless.** The PNGs are recompressed without changing a pixel, so they are larger than the guide's pictures.
- **All data is invented.** The company is the made-up Lakeshore Managed IT; client names, people, addresses,
  phone numbers, serials, keys and card numbers are fictional. Stripe, Pax8, QuickBooks and similar screens show example data, not a live connection.

A few pictures are crops of one card or pop-up rather than the whole page, as in the guide, and times such as
"2 days ago" come from the demo clock when the picture was taken.

## Regenerating

```bash
docs/user-guide/tools/build-demo.sh                                     # a fresh demo instance
NODE_PATH=$(npm root -g) node docs/user-guide/tools/run-all.cjs --clean  # writes images-clean/
docs/user-guide/tools/optimize-images.sh --lossless docs/user-guide/images-clean
```

See [`../tools/README.md`](../tools/README.md) for the requirements.
