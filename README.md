```
// This COmanage Registry enrollment plugin is intended to be used
// with an identity linking flow for ACCESS.
//
// The following enrollment steps are implemented:
//
// checkEligibility:
//   - Used to prevent linking using the ACCESS CI
//     IdP since an Organizational Identity with the
//     ACCESS CI ePPN marked as a login identifier is
//     already set up for each CO Person.
//
// finalize:
//   - Used to copy the OIDC sub value to the CO Person
//     record if it does not already exist.
```
