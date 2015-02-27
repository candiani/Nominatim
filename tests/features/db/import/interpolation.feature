@DB
Feature: Import of address interpolations
    Tests that interpolated addresses are added correctly

    Scenario: Simple even two point interpolation
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 6           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 1,1.0005

    Scenario: Simple even two point interpolation with zero beginning
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 0           | 1 1
          | 2      | place | house | 8           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 2           | 1,1.00025
          | 4           | 1,1.0005
          | 6           | 1,1.00075

    Scenario: Backwards even two point interpolation
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 6           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1.001, 1 1
        And the ways
          | id | nodes
          | 1  | 2,1
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 1,1.0005

    Scenario: Even two point interpolation with odd beginning
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 11          | 1 1
          | 2      | place | house | 16          | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 12          | 1,1.0002
          | 14          | 1,1.0006

    Scenario: Even two point interpolation with odd end
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 10          | 1 1
          | 2      | place | house | 15          | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 12          | 1,1.0004
          | 14          | 1,1.0008

    Scenario: Reverse even two point interpolation with odd beginning
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 11          | 1 1
          | 2      | place | house | 16          | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1.001, 1 1
        And the ways
          | id | nodes
          | 1  | 2,1
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 12          | 1,1.0002
          | 14          | 1,1.0006

    Scenario: Reverse even two point interpolation with odd end
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 10          | 1 1
          | 2      | place | house | 15          | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1.001, 1 1
        And the ways
          | id | nodes
          | 1  | 2,1
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 12          | 1,1.0004
          | 14          | 1,1.0008

      Scenario: Simple odd two point interpolation
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 1           | 1 1
          | 2      | place | house | 11          | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | odd         | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 3           | 1,1.0002
          | 5           | 1,1.0004
          | 7           | 1,1.0006
          | 9           | 1,1.0008

      Scenario: Odd two point interpolation with even beginning
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 7           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | odd         | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 3           | 1,1.0002
          | 5           | 1,1.0006

     Scenario: Simple all two point interpolation
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 1           | 1 1
          | 2      | place | house | 3           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | all         | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 2           | 1,1.0005

    Scenario: Simple numbered two point interpolation
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 3           | 1 1
          | 2      | place | house | 9           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | 3           | 1 1, 1 1.001
        And the ways
          | id | nodes
          | 1  | 1,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 6           | 1,1.0005

    Scenario: Even two point interpolation with intermediate empty node
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 10          | 1.001 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001, 1.001 1.001
        And the ways
          | id | nodes
          | 1  | 1,3,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 1,1.0005
          | 6           | 1,1.001
          | 8           | 1.0005,1.001


    Scenario: Even two point interpolation with intermediate duplicated empty node
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 10          | 1.001 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001, 1.001 1.001
        And the ways
          | id | nodes
          | 1  | 1,3,3,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 1,1.0005
          | 6           | 1,1.001
          | 8           | 1.0005,1.001

    Scenario: Simple even three point interpolation
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 8           | 1.001 1.001
          | 3      | place | house | 4           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001, 1.001 1.001
        And the ways
          | id | nodes
          | 1  | 1,3,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 6           | 1.0005,1.001

    Scenario: Even three point interpolation with odd center point
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 1 1
          | 2      | place | house | 8           | 1.001 1.001
          | 3      | place | house | 7           | 1 1.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 1 1, 1 1.001, 1.001 1.001
        And the ways
          | id | nodes
          | 1  | 1,3,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 1,1.0004
          | 6           | 1,1.0008

    Scenario: Interpolation on self-intersecting way
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 0 0
          | 2      | place | house | 6           | 0 0.001
          | 3      | place | house | 10          | 0 0.002
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 0 0, 0 0.001, 0 0.002, 0 0.001
        And the ways
          | id | nodes
          | 1  | 1,2,3,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 0,0.0005
          | 8           | 0,0.0015

    Scenario: Interpolation on self-intersecting way II
        Given the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | 0 0
          | 2      | place | house | 6           | 0 0.001
        And the place ways
          | osm_id | class | type   | housenumber | geometry
          | 1      | place | houses | even        | 0 0, 0 0.001, 0 0.002, 0 0.001
        And the ways
          | id | nodes
          | 1  | 1,2,3,2
        When importing
        Then way 1 expands to housenumbers
          | housenumber | centroid
          | 4           | 0,0.0005


     Scenario: addr:street on interpolation way
       Given the scene parallel-road
       And the place nodes
          | osm_id | class | type  | housenumber | geometry
          | 1      | place | house | 2           | :n-middle-w
          | 2      | place | house | 6           | :n-middle-e
          | 3      | place | house | 12          | :n-middle-w
          | 4      | place | house | 16          | :n-middle-e
       And the place ways
          | osm_id | class   | type    | housenumber | street       | geometry
          | 10     | place   | houses  | even        |              | :w-middle
          | 11     | place   | houses  | even        | Cloud Street | :w-middle
       And the place ways
          | osm_id | class   | type     | name                    | geometry
          | 2      | highway | tertiary | 'name' : 'Sun Way'      | :w-north
          | 3      | highway | tertiary | 'name' : 'Cloud Street' | :w-south
        And the ways
          | id | nodes
          | 10  | 1,100,101,102,2
          | 11  | 3,200,201,202,4
       When importing
       Then table placex contains
          | object | parent_place_id
          | N1     | W2
          | N2     | W2
          | N3     | W3
          | N4     | W3
          | W10    | W2
          | W11    | W3
       And way 10 expands exactly to housenumbers 4
       And way 11 expands exactly to housenumbers 14

     Scenario: addr:street on housenumber way
       Given the scene parallel-road
       And the place nodes
          | osm_id | class | type  | housenumber | street       | geometry
          | 1      | place | house | 2           |              | :n-middle-w
          | 2      | place | house | 6           |              | :n-middle-e
          | 3      | place | house | 12          | Cloud Street | :n-middle-w
          | 4      | place | house | 16          | Cloud Street | :n-middle-e
       And the place ways
          | osm_id | class   | type    | housenumber | geometry
          | 10     | place   | houses  | even        | :w-middle
          | 11     | place   | houses  | even        | :w-middle
       And the place ways
          | osm_id | class   | type     | name                    | geometry
          | 2      | highway | tertiary | 'name' : 'Sun Way'      | :w-north
          | 3      | highway | tertiary | 'name' : 'Cloud Street' | :w-south
        And the ways
          | id | nodes
          | 10  | 1,100,101,102,2
          | 11  | 3,200,201,202,4
       When importing
       Then table placex contains
          | object | parent_place_id
          | N1     | W2
          | N2     | W2
          | N3     | W3
          | N4     | W3
          | W10    | W2
          | W11    | W3
       And way 10 expands exactly to housenumbers 4
       And way 11 expands exactly to housenumbers 14


