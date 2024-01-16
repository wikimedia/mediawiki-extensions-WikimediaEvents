# WikimediaEvents

WikimediaEvents houses Wikimedia-specific instrumentation code utilizing EventLogging.

This extension was previously known as 'CoreEvents'; it was renamed so that its name properly
reflects its scope, which is not specific to MediaWiki core.

Although the code in this extension was developed with Wikimedia use-cases in mind, you may find
that the functionality it implements is useful. You are welcome to use and adapt this extension
under the terms of its license.

## Ownership

The infrastructure that delivers the instruments is currently maintained by the [Data Products
team](https://www.mediawiki.org/wiki/Data_Products).

The owners of individual instruments is documented in [OWNERS.md](./OWNERS.md).

Indeed, when you create a new instrument, you are expected to document the ownership of your
instrument. This expectation is enforced by the [OwnersStructureTest PHPUnit
test](./tests/phpunit/OwnersStructureTest.php), which will fail if the files that make up the
instrument aren't listed alongside contact details for you and/or your team and a description of it.

## Type Checking

As of [I63943fe97730953035a658f967a3d90cea9525a4](https://gerrit.wikimedia.org/r/q/I63943fe97730953035a658f967a3d90cea9525a4),
you can opt in to using TypeScript to type check your instrument during CI. To do so, add the relative path
to your instrument file(s) to the `"include"` section in [tsconfig.json](./tsconfig.json), e.g.

```json
{
  "include": [
    "./path/to/your/instrument.js",

    "modules/index.d.ts"
  ]
}
```

## License

WikimediaEvents is distributed under the GNU General Public License, Version 2, or, at your
discretion, any later version. The GNU General Public License is available via the Web at
<http://www.gnu.org/licenses/gpl-2.0.html>.
