<?php
class Tritac_ChannelEngineApiClient_Enums_CancellationStatus {

    const PENDING = 0;
    const CLOSED = 2; // refunded or maybe not
    const REFUND_STARTED = 3;
    const REFUND_FAILED = 4;

}