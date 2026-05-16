# AutoAssignPosition — Workflow Graph

Workflow representation of [AutoAssignPosition.php](AutoAssignPosition.php) (Johns Hopkins auto-box creation).

## High-level flow

```mermaid
flowchart TD
    Start([Calculation triggered]) --> Skip{shouldSkip?}
    Skip -->|Not sample, or storage already set & unchanged| End_Skip([Return — no-op])
    Skip -->|Process| Merge[Merge oldData + newData]

    Merge --> Extract[Extract manifest values:<br/>storageId, boxeNumber,<br/>totalRows/Cols, wantedRow/Col]
    Extract --> Resolve[getStorageById storageId]

    Resolve --> Case1{Storage resolved<br/>by system?}

    %% ── CASE 1 ─────────────────────────────────────────
    Case1 -->|Yes| HasDim{hasValidDimensions?}
    HasDim -->|No| ClearPos[position_row = null<br/>position_column = null]
    ClearPos --> End_C1NoDim([Return — stored without position])

    HasDim -->|Yes| ResolvePos1[resolvePosition<br/>wantedRow, wantedCol]
    ResolvePos1 --> WantedFree1{Wanted position<br/>free?}
    WantedFree1 -->|Yes| Place1[Use wantedRow:wantedCol]
    WantedFree1 -->|No, occupied| Scan1[findNextFreePosition<br/>scan row-major from 1,1]
    Scan1 --> FoundFree1{Free slot<br/>found?}
    FoundFree1 -->|Yes| Place1
    Place1 --> End_C1([Return — CASE 1 COMPLETE])
    FoundFree1 -->|No, box full| Case2

    %% ── CASE 2 ─────────────────────────────────────────
    Case1 -->|No| Case2[CASE 2 — manifest-driven]
    Case2 --> HasBoxeNum{boxeNumber<br/>provided?}
    HasBoxeNum -->|No| Throw1([Throw: No valid storage<br/>and no boxe_number])

    HasBoxeNum -->|Yes| FindBox[findBoxByBoxeNumber<br/>search box_number_ → NAME → BARCODE]
    FindBox --> BoxExists{Existing box<br/>found?}

    BoxExists -->|Yes| SameBox{Same box as<br/>CASE 1 fail?}
    SameBox -->|Yes, skip| CreateNew
    SameBox -->|No| ResolvePos2[resolvePosition on existing box]
    ResolvePos2 --> Found2{Position<br/>resolved?}
    Found2 -->|Yes| SetExisting[storage = existing box<br/>position = resolved]
    SetExisting --> End_C2Existing([Return — placed in existing box])
    Found2 -->|No, full| CreateNew

    BoxExists -->|No| CreateNew[createBox<br/>name/barcode = boxeNumber<br/>storage = temporary 45859<br/>shipping_status = Shipped to USA]
    CreateNew --> Created{Created<br/>OK?}
    Created -->|No| Throw2([Throw: Failed to auto-create box])
    Created -->|Yes| SetNew[storage = newBoxId<br/>position = wantedRow:wantedCol]
    SetNew --> Notify[sendBoxCreatedNotification<br/>in-app to current user]
    Notify --> End_C2New([Return — created new box])

    %% Styling
    classDef terminal fill:#e6f4ea,stroke:#2e7d32,color:#1b5e20
    classDef error fill:#fdecea,stroke:#c62828,color:#b71c1c
    classDef decision fill:#fff8e1,stroke:#f9a825,color:#5d4037
    class End_Skip,End_C1,End_C1NoDim,End_C2Existing,End_C2New terminal
    class Throw1,Throw2 error
    class Skip,Case1,HasDim,WantedFree1,FoundFree1,HasBoxeNum,BoxExists,SameBox,Found2,Created decision
```

## shouldSkip decision matrix

```mermaid
flowchart TD
    A[shouldSkip] --> B{entitytype_id<br/>== sample 3 ?}
    B -->|No| Skip([SKIP])
    B -->|Yes| C{Mode == create?}
    C -->|Yes| Run([RUN])
    C -->|Update| D{storage field in<br/>incoming data &<br/>new != old?}
    D -->|Yes| E{newStorage empty<br/>& oldStorage set?}
    E -->|Yes — clearing| Run
    E -->|No — switching| Skip
    D -->|No change| F{oldStorage set?}
    F -->|Yes — already stored| Skip
    F -->|No| Run

    classDef terminal fill:#e6f4ea,stroke:#2e7d32
    classDef skipnode fill:#eceff1,stroke:#607d8b
    class Run terminal
    class Skip skipnode
```

## resolvePosition sub-flow

```mermaid
flowchart LR
    In([wantedRow, wantedCol,<br/>storage]) --> Q1{isPositionOccupied<br/>wantedRow:wantedCol?}
    Q1 -->|No| Out1([Return wanted position])
    Q1 -->|Yes| Scan[findNextFreePosition<br/>for r in 1..rows<br/>for c in 1..cols]
    Scan --> Q2{Any free cell?}
    Q2 -->|Yes| Out2([Return first free r:c])
    Q2 -->|No| Out3([Return null — box full])
```

## State changes on `$this->data`

| Outcome | `STORAGE` | `POSITION_ROW` | `POSITION_COLUMN` |
|---|---|---|---|
| CASE 1 — no grid | unchanged | `null` | `null` |
| CASE 1 — placed | unchanged | resolved row | resolved column |
| CASE 2 — existing box | existing box id | resolved row | resolved column |
| CASE 2 — new box | new box id | wantedRow | wantedCol |
| Skipped | unchanged | unchanged | unchanged |

## Key constants

- Sample entitytype: **3** — Box entitytype: **7**
- Default box: **9×9**
- Temporary storage id: **45859**
- Shipping status "Shipped to USA": **24**
- Notification operation id: **20**
