# Changes

## [Unreleased]

- Privacy: processed-file records now store the file owner, so data export and deletion
  requests still reach them after the image itself has been deleted.
- Privacy: the data export now includes the file path hash declared in the privacy metadata.
- Records for deleted files are now removed by the scheduled task, and are no longer kept
  indefinitely.
- Optimized images now keep their owner, author, licence, source and creation time.

## v1.1.3

- Declare Moodle 5.3 support.
