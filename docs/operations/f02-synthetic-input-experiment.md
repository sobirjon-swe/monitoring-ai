# F02 synthetic secret input experiment

The native input primitive is restricted to the fixed synthetic TEST output directory and has no publication operation or database authorization. It is not approved for real credentials, DEV provisioning, or use as a production credential channel.

The owner-only TEST marker and redirected standard streams are scope checks. They do not authenticate the caller, prove database identity, or guarantee a private or unlogged consumer. Exactly 32 input bytes do not establish randomness. A future trusted caller must generate cryptographically random input, compute its verifier in memory, and protect the channel and metadata from logging.

The helper encrypts input with Windows CurrentUser DPAPI and writes ciphertext into a newly created protected file. CurrentUser DPAPI does not isolate processes running as the same Windows identity. A timed-out asynchronous read can still own its input buffer until the process exits; the experiment does not establish complete memory zeroization.

Interrupted, rejected, or test runs may leave encrypted pending files or the TEST marker in `.local/f02-synthetic-token-output`. These artifacts are retained; the helper does not automatically delete or accept them as committed receipts. Recovery, physical database commit verification, parent-path binding and publication remain separate unimplemented requirements.
